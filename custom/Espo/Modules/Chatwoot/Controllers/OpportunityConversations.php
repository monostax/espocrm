<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\AclManager;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\Table\TableFactory;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Tools\ContactReconciler;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;

/** The opportunity grants the relationship; Chatwoot grants conversation access. */
class OpportunityConversations
{
    public function __construct(
        protected User $user,
        protected EntityManager $entityManager,
        protected AclManager $aclManager,
        private DefaultAccessChecker $defaultAccessChecker,
        private TableFactory $tableFactory,
        private UserTenantResolver $tenants,
        private ChatwootApiClient $apiClient,
        private ContactReconciler $channels,
    ) {}

    public function getActionList(Request $request): object
    {
        $type = $this->entityType();
        $id = $request->getRouteParam('id');
        $accountId = filter_var($request->getQueryParam('chatwootAccountId'), FILTER_VALIDATE_INT);
        $page = filter_var($request->getQueryParam('page') ?? 1, FILTER_VALIDATE_INT);
        if (!$id || !$accountId || $accountId < 1 || !$page || $page < 1) {
            throw new BadRequest('Record ID, Chatwoot account ID and a positive page are required.');
        }

        if (!$this->aclManager->checkScope($this->user, $type, 'read') ||
            !$this->aclManager->checkScope($this->user, 'ChatwootConversation', 'read') ||
            !$this->canReadRelationship()) {
            throw new Forbidden();
        }

        $opportunity = $this->entityManager->getEntityById($type, $id);
        if (!$opportunity) {
            throw new NotFound();
        }
        if (!$this->aclManager->checkEntityRead($this->user, $opportunity) ||
            (!$this->user->isAdmin() &&
                !$this->tenants->canActForTenant($this->user, (string) $opportunity->get('tenantId')))) {
            throw new Forbidden();
        }

        $account = $this->entityManager->getRDBRepository('ChatwootAccount')->where([
            'chatwootAccountId' => $accountId,
            'tenantId' => $opportunity->get('tenantId'),
        ])->findOne();
        if (!$account) {
            throw new Forbidden();
        }

        $chatwootUser = $this->entityManager->getRDBRepository('ChatwootUser')->where([
            'assignedUserId' => $this->user->getId(),
            'platformId' => $account->get('platformId'),
        ])->findOne();
        if (!$chatwootUser) {
            throw new Forbidden();
        }

        // Preserve the default CRM ownership/team/shared-record ACL. Replace only
        // its extra inbox check, which cannot represent participants or handoffs,
        // with authorization through the viewer's own Chatwoot token.
        $scopeData = $this->tableFactory->create($this->user)->getScopeData('ChatwootConversation');
        $ids = [];
        $linked = $this->linkedConversations($opportunity, $account);
        foreach ($linked as $conversation) {
            if (!$this->canReadConversation($conversation, $scopeData)) {
                continue;
            }
            $ids[] = (int) $conversation->get('chatwootConversationId');
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return (object) ['list' => [], 'total' => 0];
        }

        $response = $this->filterForViewer($account, $chatwootUser, $ids, $page,
            $request->getQueryParam('countOnly') === 'true');

        $meta = $response['meta'];
        return (object) [
            'list' => $response['payload'],
            'total' => $meta['mine_count'] + $meta['unassigned_count'] + $meta['others_count'],
        ];
    }

    private function canReadConversation(Entity $conversation, ScopeData $scopeData): bool
    {
        return $this->defaultAccessChecker->checkEntityRead($this->user, $conversation, $scopeData);
    }

    private function filterForViewer(Entity $account, Entity $chatwootUser, array $ids, int $page, bool $countOnly = false): array
    {
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $account->get('platformId'));
        if (!$platform || !$platform->get('backendUrl')) {
            throw new Error('Chatwoot platform is not configured.');
        }

        return $this->apiClient->filterConversations(
            $platform->get('backendUrl'),
            // Never use the account/concierge token to retrieve conversation data.
            $this->userAccessToken($chatwootUser, $platform),
            (int) $account->get('chatwootAccountId'),
            $page,
            [[
                'attribute_key' => 'display_id',
                'filter_operator' => 'equal_to',
                'values' => $ids,
            ]],
            $countOnly,
        );
    }

    /** Batch the full relationship set; remote pages are shared by all returned opportunities. */
    public function whatsappEligibility(array $records, int $accountId): array
    {
        $eligible = array_fill_keys(array_keys($records), false);
        if (!$this->aclManager->checkScope($this->user, 'Opportunity', 'read') ||
            !$this->aclManager->checkScope($this->user, 'ChatwootConversation', 'read') ||
            !$this->canReadRelationship()) return $eligible;

        $accounts = $this->entityManager->getRDBRepository('ChatwootAccount')->where([
            'chatwootAccountId' => $accountId,
            'tenantId' => array_values(array_unique(array_map(fn ($record) => $record->get('tenantId'), $records))),
        ])->limit(0, 2)->find();
        $account = null;
        foreach ($accounts as $candidate) {
            // Numeric Chatwoot IDs are platform-local; never guess an ambiguous workspace.
            if ($account) return $eligible;
            $account = $candidate;
        }
        if (!$account || (!$this->user->isAdmin() &&
            !$this->tenants->canActForTenant($this->user, (string) $account->get('tenantId')))) return $eligible;
        $ids = [];
        foreach ($records as $id => $record) {
            if ($record->get('tenantId') === $account->get('tenantId') &&
                $this->aclManager->checkEntityRead($this->user, $record)) $ids[] = $id;
        }
        if (!$ids) return $eligible;
        $viewer = $this->entityManager->getRDBRepository('ChatwootUser')->where([
            'assignedUserId' => $this->user->getId(), 'platformId' => $account->get('platformId'),
        ])->findOne();
        if (!$viewer) return $eligible;

        $scopeData = $this->tableFactory->create($this->user)->getScopeData('ChatwootConversation');
        $linked = $this->entityManager->getRDBRepository('ChatwootConversation')
            ->join('opportunities', 'linkedOpportunity')
            ->where(['linkedOpportunity.id' => $ids, 'chatwootAccountId' => $account->getId()])->distinct()->find();
        $allowed = [];
        $pairs = [];
        foreach ($linked as $conversation) {
            if ($this->channels->mapChannelType($conversation->get('inboxChannelType')) !== 'whatsapp' ||
                !$this->canReadConversation($conversation, $scopeData)) continue;
            $remoteId = (int) $conversation->get('chatwootConversationId');
            if ($remoteId > 0) $allowed[$conversation->getId()] = $remoteId;
        }
        if (!$allowed) return $eligible;
        $query = SelectBuilder::create()->from('Opportunity')
            ->join('chatwootConversations', 'conversation')
            ->select([['id', 'opportunityId'], ['conversation.id', 'conversationId']])
            ->where(['id' => $ids, 'conversation.id' => array_keys($allowed)])->distinct()->build();
        foreach ($this->entityManager->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC) as $pair) {
            $pairs[$allowed[$pair['conversationId']]][] = $pair['opportunityId'];
        }
        foreach (array_chunk(array_keys($pairs), 100) as $batch) {
            $page = 1;
            $seen = [];
            do {
                $response = $this->filterForViewer($account, $viewer, $batch, $page++);
                $payload = $response['payload'];
                $before = count($seen);
                foreach ($payload as $conversation) {
                    $remoteId = (int) ($conversation['id'] ?? 0);
                    $seen[$remoteId] = true;
                    foreach ($pairs[$remoteId] ?? [] as $id) $eligible[$id] = true;
                }
                $meta = $response['meta'];
                $total = $meta['mine_count'] + $meta['unassigned_count'] + $meta['others_count'];
            } while ($payload && count($seen) > $before && count($seen) < $total);
        }
        return $eligible;
    }

    protected function entityType(): string
    {
        return 'Opportunity';
    }

    protected function canReadRelationship(): bool
    {
        return $this->aclManager->checkLink($this->user, $this->entityType(), 'chatwootConversations') &&
            $this->aclManager->checkField($this->user, $this->entityType(), 'chatwootConversations');
    }

    protected function linkedConversations(Entity $record, Entity $workspace): iterable
    {
        return $this->entityManager->getRDBRepository($record->getEntityType())
            ->getRelation($record, 'chatwootConversations')
            ->where(['chatwootAccountId' => $workspace->getId()])->find();
    }

    private function userAccessToken(Entity $chatwootUser, Entity $platform): string
    {
        $token = $chatwootUser->get('userAccessToken');
        if (!$token && $chatwootUser->get('chatwootUserId') && $platform->get('accessToken')) {
            // Older synced users do not have a stored personal token.
            $token = $this->apiClient->fetchUserAccessToken(
                $platform->get('backendUrl'),
                $platform->get('accessToken'),
                (int) $chatwootUser->get('chatwootUserId'),
            );
        }
        if (!$token) {
            throw new Forbidden('Chatwoot user access is unavailable.');
        }
        return $token;
    }
}
