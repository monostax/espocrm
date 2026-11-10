<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeImmutable;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * "Criar todas" (Self-Service PLG, "Next steps to launch" §7): the deals the analysis found in conversations still
 * without one (`new_deal` cards), listed for review and created together. Only proposals the reader could act on
 * card by card are offered: same account, deck, Chatwoot access and current revision as the Atendimento lane. Each
 * deal is created through the Opportunity record service as the reader (ACL, hooks, stamps), linked to its
 * conversation, and its card resolved, as the card's own "Criar oportunidade" does.
 */
class ProposedDeals
{
    /** Proposals looked at, and deals created, per request. */
    public const LIMIT = 50;

    public function __construct(
        private EntityManager $em,
        private SelectBuilderFactory $select,
        private Acl $acl,
        private User $user,
        private Workspace $workspace,
        private Ownership $owners,
        private Verdicts $verdicts,
        private Records $records,
        private ChatwootAccess $access,
        private ServiceFeed $service,
        private ServiceContainer $recordServices,
        private Config $config,
        private Log $log,
        private TeamTenantAccess $teams,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, funnels: list<array{id: string, name: string, isDefault: bool}>}
     */
    public function list(Request $request): array
    {
        $this->service->authorize();
        ['tenant' => $tenant, 'account' => $account] = $this->workspace->resolve($request);
        return [
            'items' => array_values($this->proposals($tenant, $account, Workspace::now($request))),
            'funnels' => array_map(fn (Entity $funnel) => [
                'id' => $funnel->getId(), 'name' => (string) $funnel->get('name'), 'isDefault' => (bool) $funnel->get('isDefault'),
            ], $this->funnels((string) $tenant->getId())),
        ];
    }

    /**
     * Body: {accountId, funnelId?, items: [{conversationId, name?, amount?}]}. Without a funnel, the workspace's
     * default (or only) funnel. Items that are no longer a proposal (created, dismissed, overtaken) are skipped.
     *
     * @return array{created: list<array{conversationId: string, opportunityId: string, name: string}>, skipped: list<string>, failed: list<string>}
     */
    public function create(Request $request): array
    {
        $this->service->authorize();
        if (!$this->acl->checkScope('Opportunity', 'create')) throw new Forbidden();
        ['tenant' => $tenant, 'account' => $account] = $this->workspace->resolve($request);
        $body = $request->getParsedBody();
        $items = $body->items ?? null;
        if (!is_array($items) || !$items || count($items) > self::LIMIT) throw new BadRequest('Invalid items.');
        [$funnelId, $stageId] = $this->stage((string) $tenant->getId(), is_string($body->funnelId ?? null) ? $body->funnelId : null);
        $proposals = $this->proposals($tenant, $account, Workspace::now($request));
        $currency = (string) ($this->config->get('defaultCurrency') ?: 'BRL');
        $teamId = $this->team($tenant);
        $created = [];
        $skipped = [];
        $failed = [];

        foreach ($items as $item) {
            $id = is_object($item) && is_string($item->conversationId ?? null) ? $item->conversationId : '';
            $proposal = $proposals[$id] ?? null;
            if (!$proposal) {
                $skipped[] = $id;
                continue;
            }
            unset($proposals[$id]);
            $name = is_string($item->name ?? null) ? trim($item->name) : '';
            $amount = $item->amount ?? $proposal['amount'];
            try {
                $deal = $this->recordServices->get('Opportunity')->create((object) [
                    'name' => $name !== '' && mb_strlen($name) <= 150 ? $name : $proposal['name'],
                    'amount' => is_numeric($amount) && (float) $amount >= 0 ? (float) $amount : 0.0,
                    'amountCurrency' => $currency,
                    'funnelId' => $funnelId,
                    'opportunityStageId' => $stageId,
                    'tenantId' => $tenant->getId(),
                    'assignedUserId' => $this->user->getId(),
                    'teamsIds' => [$teamId],
                    'chatwootConversationsIds' => [$id],
                ], CreateParams::create()->withSkipDuplicateCheck());
            } catch (Throwable $e) {
                // One deal that can't be saved doesn't stop the others; its card stays in the deck.
                $failed[] = $id;
                $this->log->warning("CatchUp: proposed deal for conversation {$id} was not created: " . $e->getMessage());
                continue;
            }
            $this->verdicts->resolve('ChatwootConversation', $id);
            $created[] = ['conversationId' => $id, 'opportunityId' => (string) $deal->getId(), 'name' => (string) $deal->get('name')];
        }

        return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * The reader's proposals, keyed by conversation id, newest activity first. A WhatsApp group mirrored once per
     * member number is offered once.
     *
     * @return array<string, array{conversationId: string, contactName: string, inboxName: ?string, name: string, amount: ?float, why: ?string}>
     */
    private function proposals(Entity $tenant, Entity $account, DateTimeImmutable $now): array
    {
        [$owner, $params] = $this->owners->conversationClause();
        $rows = $this->verdicts->proposalConversations((string) $tenant->getId(), (string) $account->getId(), $owner, $params,
            $this->user->getId(), self::LIMIT);
        $conversations = $this->access->filter($this->service->readable(array_keys($rows)));
        $dues = $this->records->dues('ChatwootConversation', array_keys($conversations));
        $found = $this->verdicts->proposals(array_map(
            fn (Entity $conversation) => Revision::ofConversation($conversation, $now, $dues[$conversation->getId()] ?? null),
            $conversations,
        ));
        $items = [];
        $groups = [];
        foreach ($conversations as $id => $conversation) {
            $deal = $found[$id]['card']['proposedDeal'] ?? null;
            $name = is_array($deal) && is_string($deal['name'] ?? null) ? trim($deal['name']) : '';
            if ($name === '') continue;
            if ($rows[$id]['grp']) {
                if (isset($groups[$rows[$id]['contact']])) continue;
                $groups[$rows[$id]['contact']] = true;
            }
            $card = $found[$id]['card'];
            $items[$id] = [
                'conversationId' => $id,
                'contactName' => (string) ($conversation->get('contactDisplayName') ?: $conversation->get('name')),
                'inboxName' => $conversation->get('inboxName'),
                'name' => $name,
                'amount' => is_numeric($deal['amount'] ?? null) ? (float) $deal['amount'] : null,
                'why' => is_string($card['whyNow'] ?? null) ? $card['whyNow'] : (is_string($card['headline'] ?? null) ? $card['headline'] : $found[$id]['reason']),
            ];
        }
        return $items;
    }

    /**
     * The funnel and its first open stage. Without a choice: the default funnel, else the only one.
     *
     * @return array{string, string}
     */
    private function stage(string $tenantId, ?string $funnelId): array
    {
        $funnels = $this->funnels($tenantId);
        $funnel = null;
        foreach ($funnels as $candidate) {
            if ($funnelId !== null ? $candidate->getId() === $funnelId : $candidate->get('isDefault')) $funnel = $candidate;
        }
        if (!$funnel && $funnelId === null && count($funnels) === 1) $funnel = $funnels[0];
        if (!$funnel) throw new BadRequest($funnels ? 'funnelRequired' : 'noFunnel');

        $stages = $this->em->getRDBRepository('OpportunityStage')
            ->where(['funnelId' => $funnel->getId(), 'isActive' => true])
            ->order('order')
            ->find();
        $first = null;
        foreach ($stages as $stage) {
            $first ??= $stage;
            $probability = (int) $stage->get('probability');
            if ($probability > 0 && $probability < 100) return [(string) $funnel->getId(), (string) $stage->getId()];
        }
        if (!$first) throw new BadRequest('noStage');
        return [(string) $funnel->getId(), (string) $first->getId()];
    }

    /** The reader's default team when it is one of this workspace's, as the deal form preselects; else its base team. */
    private function team(Entity $tenant): string
    {
        $default = (string) $this->user->get('defaultTeamId');
        if ($default !== '' && isset($this->teams->tenantIdsForTeams([$default])[(string) $tenant->getId()])) return $default;
        return (string) $tenant->get('baseUserTeamId');
    }

    /** @return list<Entity> the workspace's active funnels the reader may read */
    private function funnels(string $tenantId): array
    {
        $query = $this->select->create()->from('Funnel')->withStrictAccessControl()->buildQueryBuilder()
            ->where(['tenantId' => $tenantId, 'isActive' => true])
            ->order('isDefault', 'DESC')
            ->order('name')
            ->build();
        return iterator_to_array($this->em->getRDBRepository('Funnel')->clone($query)->find(), false);
    }
}
