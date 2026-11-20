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
use Espo\Entities\User;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

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
            if ($this->service->hasOpenDeal($id)) {
                $deals = $this->em->getRDBRepository('Opportunity')->where(['tenantId' => $tenant->getId(),
                    'chatwootConversations.id' => $id, 'status!=' => ['Won', 'Lost']])->join('chatwootConversations')->find();
                foreach ($deals as $deal) {
                    if ($this->acl->checkEntityRead($deal)) return ['existing' => true, 'id' => $deal->getId()];
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

        $deal = $this->recordServices->get('Opportunity')->create((object) [
            'name' => $name,
            'amount' => is_numeric($amount) ? (float) $amount : 0.0,
            'amountCurrency' => (string) ($this->config->get('defaultCurrency') ?: 'BRL'),
            'funnelId' => $funnelId,
            'opportunityStageId' => $stageId,
            'tenantId' => $tenantId,
            'assignedUserId' => $owner,
            'teamsIds' => [$teamId],
            'chatwootConversationsIds' => [$id],
        ], CreateParams::create()->withSkipDuplicateCheck());
        $this->verdicts->resolve('ChatwootConversation', $id);
        $this->acts->record('ChatwootConversation', $id, $this->user->getId(), 'deal');

        return [
            'id' => (string) $deal->getId(), 'name' => (string) $deal->get('name'), 'status' => (string) $deal->get('status'),
            'funnelId' => $funnelId, 'opportunityStageId' => $stageId, 'assignedUserId' => $owner,
        ];
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
