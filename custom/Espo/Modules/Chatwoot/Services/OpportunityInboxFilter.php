<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Acl\InboxAccessResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\Part\Expression as Expr;

/** One authorization path for navigation options, counts and list membership. */
class OpportunityInboxFilter
{
    public function __construct(
        private EntityManager $em,
        private User $user,
        private Acl $acl,
        private SelectBuilderFactory $select,
        private OpportunityBulkPostAccess $workspaceAccess,
        private InboxAccessResolver $inboxAccess,
        private ChatwootApiClient $api,
    ) {}

    public function matchingIds(mixed $value): Select
    {
        if (!is_array($value) && !$value instanceof \stdClass) {
            throw new BadRequest('An inbox and Chatwoot account are required.');
        }
        $value = (array) $value;
        $inboxId = $value['inboxId'] ?? null;
        if (!is_string($inboxId) || $inboxId === '') {
            throw new BadRequest('An inbox is required.');
        }
        if (!$this->canRead()) throw new Forbidden();

        $account = $this->account($value['chatwootAccountId'] ?? null);
        $inboxes = $this->inboxes($account, $inboxId);
        if (!isset($inboxes[$inboxId])) throw new Forbidden();

        $scope = $this->select->create()->from('Opportunity')->withStrictAccessControl()->buildQueryBuilder();
        $scope->where(['tenantId' => $account->get('tenantId')]);
        $conversations = $this->visibleConversations($account, $inboxes, $scope);

        return $this->pairs($account, $scope, $conversations)->select(['id'])->build();
    }

    /** @return list<array{id: string, name: string, count: int}> */
    public function counts(SelectBuilder $scope, mixed $chatwootAccountId): array
    {
        // Older navigation callers have no workspace. Never infer an all-tenant scope.
        if ($chatwootAccountId === null || !$this->canRead()) return [];

        $account = $this->account($chatwootAccountId);
        $inboxes = $this->inboxes($account);
        if (!$inboxes) return [];

        $conversations = $this->visibleConversations($account, $inboxes, $scope);
        if (!$conversations) return [];

        // Deduplicate opportunity/inbox pairs before aggregation: several linked
        // conversations (or team ACL joins) must never multiply an opportunity.
        $pairs = $this->pairs($account, $scope, $conversations)
            ->select([['id', 'opportunityId'], ['inboxConversation.inboxId', 'inboxId']])->build();
        $query = SelectBuilder::create()->fromQuery($pairs, 'inboxOpportunities')
            ->select(Expr::alias('inboxOpportunities.inboxId'), 'inboxId')
            ->select(Expr::count(Expr::alias('inboxOpportunities.opportunityId')), 'count')
            ->group(Expr::alias('inboxOpportunities.inboxId'))->build();
        $result = [];
        foreach ($this->em->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $inbox = $inboxes[$row['inboxId']];
            $result[] = ['id' => $inbox->getId(), 'name' => (string) $inbox->get('name'), 'count' => (int) $row['count']];
        }
        usort($result, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $result;
    }

    private function canRead(): bool
    {
        return ($this->user->isRegular() || $this->user->isAdmin()) &&
            $this->acl->checkScope('Opportunity', 'read') &&
            $this->acl->checkScope('ChatwootConversation', 'read') &&
            $this->acl->checkScope('ChatwootInbox', 'read') &&
            $this->acl->checkScope('ChatwootAccount', 'read') &&
            $this->acl->checkLink('Opportunity', 'chatwootConversations') &&
            $this->acl->checkField('Opportunity', 'chatwootConversations') &&
            $this->acl->checkLink('ChatwootConversation', 'inbox') &&
            $this->acl->checkField('ChatwootConversation', 'inbox') &&
            $this->acl->checkField('ChatwootInbox', 'name');
    }

    private function account(mixed $id): Entity
    {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new BadRequest('A positive Chatwoot account ID is required.');

        return $this->workspaceAccess->workspace($id);
    }

    /** @return array<string, Entity> */
    private function inboxes(Entity $account, ?string $id = null): array
    {
        $query = $this->select->create()->from('ChatwootInbox')->withStrictAccessControl()->buildQueryBuilder()
            ->where(['chatwootAccountId' => $account->getId()]);
        if ($id !== null) $query->where(['id' => $id]);
        $allowed = $this->inboxAccess->getAllowedInboxIdList($this->user);
        if ($allowed !== null) $query->where(['id' => $allowed]);
        $result = [];
        foreach ($this->em->getRDBRepository('ChatwootInbox')->clone($query->build())->find() as $inbox) {
            if ($this->acl->checkEntityRead($inbox)) $result[$inbox->getId()] = $inbox;
        }
        return $result;
    }

    private function scopeIds(Entity $account, SelectBuilder $scope): Select
    {
        return (clone $scope)->select(['id'])->order([])->limit(null, null)
            ->where(['opportunity.tenantId' => $account->get('tenantId')])->build();
    }

    private function pairs(Entity $account, SelectBuilder $scope, array $conversations): SelectBuilder
    {
        $matches = [];
        foreach ($conversations as $inboxId => $ids) {
            $matches[] = ['inboxConversation.inboxId' => $inboxId, 'inboxConversation.id' => $ids];
        }
        return SelectBuilder::create()->from('Opportunity')
            ->where(['id=s' => $this->scopeIds($account, $scope)])
            ->join('chatwootConversations', 'inboxConversation')
            ->where([
                'inboxConversation.chatwootAccountId' => $account->getId(),
            ])->where($matches ? ['OR' => $matches] : ['id' => []])->distinct();
    }

    /** CRM ownership/team ACL AND live Chatwoot permissions, never an account token. */
    private function visibleConversations(Entity $account, array $inboxes, SelectBuilder $scope): array
    {
        $query = $this->select->create()->from('ChatwootConversation')->withStrictAccessControl()->buildQueryBuilder()
            ->join('opportunities', 'inboxOpportunity')->where([
                'inboxOpportunity.id=s' => $this->scopeIds($account, $scope),
                'chatwootAccountId' => $account->getId(),
                'inboxId' => array_keys($inboxes),
            ])->distinct()->build();
        $candidates = [];
        foreach ($this->em->getRDBRepository('ChatwootConversation')->clone($query)->find() as $conversation) {
            // The custom CRM select filter handles inbox membership; the entity
            // check additionally enforces the default own/team/shared-record ACL.
            if (!$this->acl->checkEntityRead($conversation)) continue;
            $remoteId = (int) $conversation->get('chatwootConversationId');
            if ($remoteId > 0) $candidates[$remoteId] = $conversation;
        }
        if (!$candidates) return [];

        $viewer = $this->em->getRDBRepository('ChatwootUser')->where([
            'assignedUserId' => $this->user->getId(), 'platformId' => $account->get('platformId'),
        ])->findOne();
        $platform = $this->em->getEntityById('ChatwootPlatform', $account->get('platformId'));
        if (!$viewer || !$platform || !$platform->get('backendUrl')) return [];
        $token = $viewer->get('userAccessToken');
        if (!$token && $viewer->get('chatwootUserId') && $platform->get('accessToken')) {
            $token = $this->api->fetchUserAccessToken(
                $platform->get('backendUrl'), $platform->get('accessToken'), (int) $viewer->get('chatwootUserId')
            );
        }
        if (!$token) return [];

        $visible = [];
        foreach (array_chunk(array_keys($candidates), 500) as $ids) {
            $rows = $this->api->getConversationVisibility(
                $platform->get('backendUrl'), $token, (int) $account->get('chatwootAccountId'), $ids
            );
            foreach ($rows as $row) {
                $conversation = $candidates[$row['id']] ?? null;
                if (!$conversation) continue;
                $inbox = $inboxes[$conversation->get('inboxId')];
                // Do not count a stale CRM inbox assignment after a Chatwoot move.
                if ((int) $row['inbox_id'] !== (int) $inbox->get('chatwootInboxId')) continue;
                $visible[$inbox->getId()][] = $conversation->getId();
            }
        }
        return $visible;
    }
}
