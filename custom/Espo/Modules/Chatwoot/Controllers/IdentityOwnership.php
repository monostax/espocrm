<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Tools\PhoneNormalizer;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class IdentityOwnership
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private UserTenantResolver $tenants,
        private ChatwootApiClient $api,
    ) {}

    public function getActionPreview(Request $request): object
    {
        [$conversation, $identity, $account, $platform, $token, $remote] = $this->context($request);
        return (object) [
            'identityId' => $identity->getId(),
            'contactId' => $identity->get('contactId'),
            'contactName' => $identity->get('contactName'),
            'channelType' => $identity->get('channelType'),
            'sourceId' => $identity->get('sourceId'),
            'ownershipStatus' => $identity->get('ownershipStatus') ?? 'unverified',
            'messages' => $remote['messages'] ?? [],
        ];
    }

    public function postActionReport(Request $request): object
    {
        [$conversation, $identity, $account, $platform, $token, $remote] = $this->context($request);
        $body = $request->getParsedBody();
        if (($body->identityId ?? null) !== $identity->getId() ||
            ($body->contactId ?? null) !== $identity->get('contactId') ||
            ($body->sourceId ?? null) !== $identity->get('sourceId') ||
            ($body->channelType ?? null) !== $identity->get('channelType')) {
            throw new Conflict('The contact identity changed. Reload before reporting it.');
        }
        $messageId = $body->evidenceMessageId ?? null;
        if ($messageId !== null && ((!is_int($messageId) && !is_string($messageId)) ||
            !filter_var($messageId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ||
            !in_array((int) $messageId, array_column($remote['messages'] ?? [], 'id'), true))) {
            throw new BadRequest('Evidence must be an incoming message from this conversation.');
        }
        $identity = $this->entityManager->getTransactionManager()->run(function () use ($identity, $conversation, $messageId): Entity {
            $locked = $this->entityManager->getRDBRepository('ContactChannelIdentity')->where(['id' => $identity->getId()])->forUpdate()->findOne();
            if (!$locked || $locked->get('contactId') !== $identity->get('contactId') ||
                $locked->get('tenantId') !== $identity->get('tenantId') || $locked->get('sourceId') !== $identity->get('sourceId') ||
                $locked->get('channelType') !== $identity->get('channelType')) {
                throw new Conflict('The contact identity changed. Reload before reporting it.');
            }
            if ($locked->get('ownershipStatus') !== 'rejected') {
                $locked->set([
                    'ownershipStatus' => 'rejected',
                    'ownershipReason' => 'wrong_person',
                    'ownershipReviewedAt' => date('Y-m-d H:i:s'),
                    'ownershipReviewedById' => $this->user->getId(),
                    'ownershipConversationId' => $conversation->getId(),
                    'ownershipEvidenceMessageId' => $messageId === null ? null : (string) $messageId,
                ]);
                $this->entityManager->saveEntity($locked);
            }
            return $locked;
        });

        // Keep the local rejection on remote failure. A retry safely finishes the mirrors;
        // never claim success while Chatwoot can still send to the rejected destination.
        $report = [
            'identity_kind' => $remote['identity_kind'], 'source_id' => $remote['source_id'],
            'evidence_message_id' => $messageId,
        ];
        $this->api->identityOwnership($platform->get('backendUrl'), $token,
            (int) $account->get('chatwootAccountId'), (int) $conversation->get('chatwootConversationId'), $report);

        if (in_array($remote['identity_kind'], ['phone', 'email'], true)) {
            $this->mirror($identity, $report);
        }
        $identity->set('ownershipSyncedAt', date('Y-m-d H:i:s'));
        $this->entityManager->saveEntity($identity);
        return (object) ['ownershipStatus' => 'rejected'];
    }

    private function context(Request $request): array
    {
        foreach (['ChatwootConversation', 'Contact', 'ContactChannelIdentity'] as $scope) {
            if (!$this->acl->checkScope($scope, 'read')) {
                throw new Forbidden();
            }
        }
        foreach (['sourceId', 'contact', 'channelType', 'ownershipStatus'] as $field) {
            if (!$this->acl->checkField('ContactChannelIdentity', $field)) {
                throw new Forbidden();
            }
        }
        if (!$this->acl->checkField('ContactChannelIdentity', 'ownershipStatus', 'edit') ||
            !$this->acl->checkLink('ContactChannelIdentity', 'contact') ||
            !$this->acl->checkLink('ChatwootConversation', 'channelIdentity') ||
            !$this->acl->checkLink('ChatwootConversation', 'contact') ||
            !$this->acl->checkField('ChatwootConversation', 'channelIdentity') ||
            !$this->acl->checkField('ChatwootConversation', 'contact') ||
            !$this->acl->checkField('Contact', 'name')) {
            throw new Forbidden();
        }
        $accountId = filter_var($request->getRouteParam('accountId'), FILTER_VALIDATE_INT);
        $conversationId = filter_var($request->getRouteParam('conversationId'), FILTER_VALIDATE_INT);
        if (!$accountId || $accountId < 1 || !$conversationId || $conversationId < 1) {
            throw new BadRequest('A workspace and conversation are required.');
        }
        $accounts = [];
        foreach ($this->entityManager->getRDBRepository('ChatwootAccount')->where(['chatwootAccountId' => $accountId])->find() as $candidate) {
            if ($this->acl->check($candidate, 'read') &&
                ($this->user->isAdmin() || $this->tenants->canActForTenant($this->user, (string) $candidate->get('tenantId')))) {
                $accounts[] = $candidate;
            }
        }
        if (count($accounts) !== 1) {
            throw new Forbidden();
        }
        $account = $accounts[0];
        $conversation = $this->entityManager->getRDBRepository('ChatwootConversation')->where([
            'chatwootAccountId' => $account->getId(), 'chatwootConversationId' => $conversationId,
        ])->findOne();
        if (!$conversation) {
            throw new NotFound('The conversation has not been synchronized with CRM yet.');
        }
        if (!$this->acl->check($conversation, 'read')) {
            throw new Forbidden();
        }
        $identity = $conversation->get('channelIdentityId')
            ? $this->entityManager->getEntityById('ContactChannelIdentity', $conversation->get('channelIdentityId')) : null;
        $contact = $conversation->get('contactId')
            ? $this->entityManager->getEntityById('Contact', $conversation->get('contactId')) : null;
        if (!$identity || !$contact) {
            throw new Conflict('The conversation has no linked contact identity yet.');
        }
        if ($identity->get('contactId') !== $contact->getId() || $identity->get('tenantId') !== $account->get('tenantId') ||
            $contact->get('tenantId') !== $account->get('tenantId') ||
            !$this->acl->check($contact, 'read') || !$this->acl->check($identity, 'read') ||
            !$this->acl->check($contact, 'edit') || !$this->acl->check($identity, 'edit')) {
            throw new Forbidden();
        }
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $account->get('platformId'));
        $viewer = $this->entityManager->getRDBRepository('ChatwootUser')->where([
            'platformId' => $account->get('platformId'), 'assignedUserId' => $this->user->getId(),
        ])->findOne();
        if (!$viewer || !$platform || !$platform->get('backendUrl')) {
            throw new Forbidden();
        }
        $token = $viewer->get('userAccessToken');
        if (!$token && $platform->get('accessToken')) {
            $token = $this->api->fetchUserAccessToken($platform->get('backendUrl'), $platform->get('accessToken'), (int) $viewer->get('chatwootUserId'));
        }
        if (!$token) {
            throw new Forbidden();
        }
        // The viewer's token authorizes actual conversation access, including participant ACL.
        $remote = $this->api->identityOwnership($platform->get('backendUrl'), $token, $accountId, $conversationId);
        $source = (string) $identity->get('sourceId');
        if ($remote['identity_kind'] === 'phone') {
            if (!in_array($identity->get('channelType'), ['whatsapp', 'sms'], true)) {
                throw new Conflict('The conversation identity does not match its destination.');
            }
            $source = PhoneNormalizer::normalize($source);
        }
        $bridge = $this->entityManager->getEntityById('ChatwootContact', $conversation->get('chatwootContactId'));
        if (!$bridge || $bridge->get('chatwootAccountId') !== $account->getId() || $bridge->get('contactId') !== $contact->getId() ||
            (int) $bridge->get('chatwootContactId') !== (int) $remote['contact_id'] || $source !== $remote['source_id'] ||
            ($remote['identity_kind'] === 'email' && $identity->get('channelType') !== 'email')) {
            throw new Conflict('The conversation identity does not match its destination.');
        }
        return [$conversation, $identity, $account, $platform, $token, $remote];
    }

    private function mirror(Entity $identity, array $report): void
    {
        foreach ($this->entityManager->getRDBRepository('ChatwootContact')->where(['contactId' => $identity->get('contactId')])->find() as $bridge) {
            $account = $this->entityManager->getEntityById('ChatwootAccount', $bridge->get('chatwootAccountId'));
            if (!$account || $account->get('tenantId') !== $identity->get('tenantId')) {
                continue;
            }
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', $account->get('platformId'));
            if (!$platform || !$account->get('apiKey')) {
                throw new Conflict('Cannot synchronize the identity correction to all contact workspaces.');
            }
            $this->api->mirrorIdentityRejection($platform->get('backendUrl'), $account->get('apiKey'),
                (int) $account->get('chatwootAccountId'), (int) $bridge->get('chatwootContactId'),
                $report + ['crm_identity_id' => $identity->getId(), 'crm_reviewed_by_id' => $identity->get('ownershipReviewedById')]);
        }
    }
}
