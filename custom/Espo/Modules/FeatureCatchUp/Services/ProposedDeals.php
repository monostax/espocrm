<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Core\Acl;
use Espo\Core\Acl\AssignmentChecker\AssignmentCheckerManager;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Classes\CardGone;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * "Criar oportunidade" on an Atendimento card (decided 2026-11-11): the deal the analysis found in a conversation still
 * without one (`new_deal`, or since 2026-11-12 a reply card whose customer shows buying intent and waits on us) is
 * created in one tap, with the name, amount, funnel and stage the card shows. The reader
 * may change any of them on the card first. It replaced "Criar todas" (the banner and its review list), which put every
 * deal in one funnel and its first stage.
 *
 * The deal is created through the Opportunity record service as the reader (ACL, stage requirements, hooks, stamps),
 * linked to its conversation, and the card is resolved. It goes to the conversation's Chatwoot assignee when that is a
 * person the reader may assign to, otherwise to the reader.
 */
class ProposedDeals
{
    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private User $user,
        private Workspace $workspace,
        private Verdicts $verdicts,
        private ServiceFeed $service,
        private DealTargets $targets,
        private ServiceContainer $recordServices,
        private AssignmentCheckerManager $assignment,
        private Config $config,
        private TeamTenantAccess $teams,
        private Acts $acts,
        private DealStates $dealStates,
        private Log $log,
    ) {}

    /**
     * Body: {accountId, snapshot, name?, amount?, funnelId?, stageId?}. A field left out keeps the proposal's; a funnel
     * and stage are chosen together. 409 when the conversation moved since the card was shown, already has an open
     * deal, or no longer proposes one; stage requirements answer as they do for any deal (409
     * `opportunityStageRequirements`).
     *
     * @return array{id: string, name: string, status: string, funnelId: string, opportunityStageId: string, assignedUserId: string}
     */
    public function create(Request $request, string $id): array
    {
        if (!$this->acl->checkScope('Opportunity', 'create')) throw new Forbidden();
        $this->service->authorize();
        $workspace = $this->workspace->resolve($request);
        $conversation = $this->service->conversation($id, $workspace['account']);
        $tenant = $workspace['tenant'];
        return $this->em->getTransactionManager()->run(function () use ($id, $request, $conversation, $tenant): array {
            // Two readers accepting the same proposal must not create two opportunities.
            $locked = $this->em->getRDBRepository('ChatwootConversation')->where(['id' => $id,
                'chatwootAccountId' => $conversation->get('chatwootAccountId')])->forUpdate()->findOne();
            if (!$locked) throw new Forbidden();
            ConversationCycle::requireCurrent($locked);
            // The contact's open deal, in this conversation or another of its inboxes: that one, never a second.
            $open = $this->service->contactOpenDeals($id, (string) $tenant->getId());
            if ($open) {
                foreach ($open as $dealId) {
                    $deal = $this->em->getEntityById('Opportunity', $dealId);
                    if ($deal && $this->acl->checkEntityRead($deal)) return ['existing' => true, 'id' => $dealId];
                }
                return ['existing' => true];
            }
            return $this->createFromProposal($id, Revision::ofConversation($locked), $tenant, $request->getParsedBody());
        });
    }

    private function createFromProposal(string $id, string $revision, Entity $tenant, object $body): array
    {
        $tenantId = $tenant->getId();
        $card = $this->verdicts->proposals([$id => $revision])[$id]['card'] ?? null;
        $proposal = $card['proposedDeal'] ?? null;
        if (!is_array($proposal)) throw new Conflict('No current deal proposal.');
        // A deal closed on the conversation since the card was made may be this one: the card is analysed again first.
        if (Verdicts::missesClosedDeal($card, ServiceFeed::dealKeys($this->service->closedDeals($id, $tenantId)))) {
            throw new Conflict('A linked deal has already closed.');
        }

        $name = property_exists($body, 'name') ? (is_string($body->name) ? trim($body->name) : '') : (string) ($proposal['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 150) throw new BadRequest('Invalid name.');
        $amount = property_exists($body, 'amount') ? $body->amount : ($proposal['amount'] ?? null);
        if ($amount !== null && $amount !== '' && (!is_numeric($amount) || (float) $amount < 0)) throw new BadRequest('Invalid amount.');
        $funnelId = self::id($body->funnelId ?? null);
        $stageId = self::id($body->stageId ?? null);
        [$funnelId, $stageId] = $funnelId === null && $stageId === null
            ? $this->proposed($tenantId, $proposal)
            : $this->targets->resolve($tenantId, $funnelId, $stageId);
        $teamId = $this->team($tenant);
        $owner = $this->owner($id, $teamId);

        $deal = $this->createDeal([
            'name' => $name,
            'amount' => is_numeric($amount) ? (float) $amount : 0.0,
            'amountCurrency' => (string) ($this->config->get('defaultCurrency') ?: 'BRL'),
            'funnelId' => $funnelId,
            'opportunityStageId' => $stageId,
            'tenantId' => $tenantId,
            'assignedUserId' => $owner,
            'teamsIds' => [$teamId],
            'chatwootConversationsIds' => [$id],
        ]);
        $this->verdicts->resolve('ChatwootConversation', $id);
        $this->acts->record('ChatwootConversation', $id, $this->user->getId(), 'deal');

        return [
            'id' => (string) $deal->getId(), 'name' => (string) $deal->get('name'), 'status' => (string) $deal->get('status'),
            'funnelId' => $funnelId, 'opportunityStageId' => $stageId, 'assignedUserId' => $owner,
        ];
    }

    /**
     * Auto-creates the opportunity Em dia found in a conversation when the account uses `create` mode (the default).
     * Stamps `createdByAIAgent = true`, falls back to the funnel's first open stage if the proposed stage has required
     * fields, and returns null (leaving the card as a suggestion) if the reader lacks permission, the contact already has
     * an open deal in any of its conversations, a closed deal the card wasn't analysed with appeared, or creation is refused.
     *
     * @param array<string, mixed> $card
     * @param list<string> $closedDeals
     * @return ?array{deal: array<string, mixed>, revision: ?string, closedKey: ?string}
     */
    public function autoCreate(string $conversationId, string $tenantId, array $card, array $closedDeals): ?array
    {
        if (!$this->acl->checkScope('Opportunity', 'create')) return null;
        $proposal = $card['proposedDeal'] ?? null;
        if (!is_array($proposal)) return null;
        $name = trim((string) ($proposal['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) return null;
        $rawAmount = $proposal['amount'] ?? null;
        if ($rawAmount !== null && $rawAmount !== '' && (!is_numeric($rawAmount) || (float) $rawAmount < 0)) {
            $rawAmount = null;
        }
        $tenant = $this->em->getEntityById('Tenant', $tenantId);
        if (!$tenant) return null;

        try {
            $created = $this->em->getTransactionManager()->run(function () use ($conversationId, $tenantId, $tenant, $card, $proposal, $name, $rawAmount): ?array {
                $locked = $this->em->getRDBRepository('ChatwootConversation')->where(['id' => $conversationId])->forUpdate()->findOne();
                if (!$locked) return null;
                try {
                    ConversationCycle::requireCurrent($locked);
                } catch (CardGone) {
                    return null;
                }
                if ($this->service->contactOpenDeals($conversationId, $tenantId)) return null;
                if (Verdicts::missesClosedDeal($card, ServiceFeed::dealKeys($this->service->closedDeals($conversationId, $tenantId)))) {
                    return null;
                }
                [$funnelId, $stageId] = $this->proposed($tenantId, $proposal);
                $teamId = $this->team($tenant);
                $owner = $this->owner($conversationId, $teamId);
                $contactId = $this->contactId($locked);

                $data = [
                    'name' => $name,
                    'amount' => is_numeric($rawAmount) ? (float) $rawAmount : 0.0,
                    'amountCurrency' => (string) ($this->config->get('defaultCurrency') ?: 'BRL'),
                    'funnelId' => $funnelId,
                    'opportunityStageId' => $stageId,
                    'tenantId' => $tenantId,
                    'assignedUserId' => $owner,
                    'teamsIds' => [$teamId],
                    'chatwootConversationsIds' => [$conversationId],
                    'createdByAIAgent' => true,
                ];
                if ($contactId !== null) $data['contactId'] = $contactId;

                $deal = $this->createWithFallback($data, $funnelId, $stageId);
                if (!$deal) return null;
                return ['deal' => $deal, 'funnelId' => $funnelId, 'stageId' => (string) $deal->get('opportunityStageId')];
            });
        } catch (BadRequest|Conflict|Forbidden $e) {
            $this->log->info("CatchUp auto-create skipped for conversation {$conversationId}: " . $e->getMessage());
            return null;
        } catch (Throwable $e) {
            $this->log->warning("CatchUp auto-create failed for conversation {$conversationId}: " . $e->getMessage());
            return null;
        }

        if (!$created) return null;
        /** @var Entity $deal */
        $deal = $created['deal'];
        $dealId = (string) $deal->getId();
        $funnelId = (string) $created['funnelId'];
        $stageId = (string) $created['stageId'];
        $status = (string) $deal->get('status');
        $funnel = $this->em->getEntityById('Funnel', $funnelId);
        $stage = $this->em->getEntityById('OpportunityStage', $stageId);

        $revision = null;
        $closedKey = null;
        if (in_array($status, ['Won', 'Lost'], true)) {
            $closedKey = ServiceFeed::dealKeys([$deal])[0] ?? null;
        } else {
            $this->dealStates->refresh([$dealId]);
            $fresh = $this->em->getEntityById('Opportunity', $dealId) ?: $deal;
            $state = $this->dealStates->load([$dealId])[$dealId] ?? null;
            $revision = Revision::of($fresh, $state, null, null);
        }

        return [
            'deal' => [
                'id' => $dealId,
                'name' => (string) $deal->get('name'),
                'amount' => is_numeric($rawAmount) ? (float) $rawAmount : null,
                'amountCurrency' => (string) ($deal->get('amountCurrency') ?: ($this->config->get('defaultCurrency') ?: 'BRL')),
                'status' => $status,
                'funnelId' => $funnelId,
                'funnelName' => (string) ($funnel?->get('name') ?? ''),
                'stageId' => $stageId,
                'stageName' => (string) ($stage?->get('name') ?? ''),
                'stageWon' => (int) ($stage?->get('probability') ?? 0) >= 100,
                'createdByAi' => true,
            ],
            'revision' => $revision,
            'closedKey' => $closedKey,
        ];
    }

    /**
     * Whether the conversation's contact already has an open deal, here or in another of its conversations
     * (ServiceFeed::contactOpenDeals): Em dia then proposes no deal for it (Summary).
     */
    public function covered(string $conversationId, string $tenantId): bool
    {
        return $this->service->contactOpenDeals($conversationId, $tenantId) !== [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createWithFallback(array $data, string $funnelId, string $stageId): ?Entity
    {
        try {
            return $this->createDeal($data);
        } catch (Conflict) {
            $fallbackStageId = $this->targets->firstOpenStageId($funnelId);
            if ($fallbackStageId === null || $fallbackStageId === $stageId) return null;
            $data['opportunityStageId'] = $fallbackStageId;
            return $this->createDeal($data);
        }
    }

    /**
     * Through the Opportunity record service as the reader. Espo 10's create() answers a CreateResult, not the entity
     * (until 2026-11-25 every auto-created deal failed on that and rolled back).
     *
     * @param array<string, mixed> $data
     */
    private function createDeal(array $data): Entity
    {
        return $this->recordServices->get('Opportunity')->create((object) $data, CreateParams::create()->withSkipDuplicateCheck())->getEntity();
    }

    private function contactId(Entity $conversation): ?string
    {
        $id = $conversation->get('contactId');
        if (!is_string($id) || $id === '') return null;
        $contact = $this->em->getEntityById('Contact', $id);
        return $contact && $this->acl->checkEntityRead($contact) ? $id : null;
    }

    /**
     * The proposal's funnel and stage when the reader may use them; otherwise the default funnel's first open stage.
     *
     * @param array<string, mixed> $proposal
     * @return array{string, string}
     */
    private function proposed(string $tenantId, array $proposal): array
    {
        $funnelId = self::id($proposal['funnelId'] ?? null);
        $stageId = self::id($proposal['stageId'] ?? null);
        if ($funnelId !== null && $stageId !== null) {
            try {
                return $this->targets->resolve($tenantId, $funnelId, $stageId);
            } catch (BadRequest) {
                // A funnel this reader can't read, or one that was removed since the analysis.
            }
        }
        return $this->targets->resolve($tenantId, null, null);
    }

    /** The conversation's assignee when a person the reader may assign this deal to, else the reader. */
    private function owner(string $conversationId, string $teamId): string
    {
        $assignee = $this->service->assignee($conversationId);
        if ($assignee === null || $assignee === $this->user->getId()) return $this->user->getId();
        $draft = $this->em->getRDBRepository('Opportunity')->getNew();
        $draft->set(['assignedUserId' => $assignee, 'teamsIds' => [$teamId]]);
        return $this->assignment->check($this->user, $draft) ? $assignee : $this->user->getId();
    }

    /** The reader's default team when it is one of this workspace's, as the deal form preselects; else its base team. */
    private function team(Entity $tenant): string
    {
        $default = (string) $this->user->get('defaultTeamId');
        if ($default !== '' && isset($this->teams->tenantIdsForTeams([$default])[(string) $tenant->getId()])) return $default;
        return (string) $tenant->get('baseUserTeamId');
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,36}$/D', $value) ? $value : null;
    }
}
