<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\AclManager;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\Table\TableFactory;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** The opportunity grants the relationship; Chatwoot grants conversation access. */
class OpportunityConversations
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
        private AclManager $aclManager,
        private DefaultAccessChecker $defaultAccessChecker,
        private TableFactory $tableFactory,
        private UserTenantResolver $tenants,
        private ChatwootApiClient $apiClient,
    ) {}

    public function getActionList(Request $request): object
    {
        $id = $request->getRouteParam('id');
        $accountId = filter_var($request->getQueryParam('chatwootAccountId'), FILTER_VALIDATE_INT);
        $page = filter_var($request->getQueryParam('page') ?? 1, FILTER_VALIDATE_INT);
        if (!$id || !$accountId || $accountId < 1 || !$page || $page < 1) {
            throw new BadRequest('Opportunity ID, Chatwoot account ID and a positive page are required.');
        }

        if (!$this->aclManager->checkScope($this->user, 'Opportunity', 'read') ||
            !$this->aclManager->checkScope($this->user, 'ChatwootConversation', 'read') ||
            !$this->aclManager->checkLink($this->user, 'Opportunity', 'chatwootConversations') ||
            !$this->aclManager->checkField($this->user, 'Opportunity', 'chatwootConversations')) {
            throw new Forbidden();
        }

        $opportunity = $this->entityManager->getEntityById('Opportunity', $id);
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
        $linked = $this->entityManager->getRDBRepository('Opportunity')
            ->getRelation($opportunity, 'chatwootConversations')
            ->where(['chatwootAccountId' => $account->getId()])
            ->find();
        foreach ($linked as $conversation) {
            if (!$this->defaultAccessChecker->checkEntityRead($this->user, $conversation, $scopeData)) {
                continue;
            }
            $ids[] = (int) $conversation->get('chatwootConversationId');
        }
        if (!$ids) {
            return (object) ['list' => [], 'total' => 0];
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $account->get('platformId'));
        if (!$platform || !$platform->get('backendUrl')) {
            throw new Error('Chatwoot platform is not configured.');
        }

        $response = $this->apiClient->filterConversations(
            $platform->get('backendUrl'),
            // Never use the account/concierge token to retrieve conversation data.
            $this->userAccessToken($chatwootUser, $platform),
            $accountId,
            $page,
            [[
                'attribute_key' => 'display_id',
                'filter_operator' => 'equal_to',
                'values' => $ids,
            ]],
        );

        $meta = $response['meta'];
        return (object) [
            'list' => $response['payload'],
            'total' => $meta['mine_count'] + $meta['unassigned_count'] + $meta['others_count'],
        ];
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
