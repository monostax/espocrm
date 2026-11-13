<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Classes\AutomationActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\Action;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Opens (or reuses) the WhatsApp conversation with the record's person and posts a private note there,
 * optionally @mentioning an AI agent so it acts on the note (for example, writes the first message).
 *
 * Nothing reaches the customer from this action. The note is written with the run-as user's own Chatwoot
 * token: Chatwoot then applies that person's inbox access, and the AI does not discard the note as one of
 * its own (the account key belongs to the integration user, which counts as an AI).
 */
class OpenConversationWithNote implements Action
{
    public function __construct(
        private JourneyWhatsAppOutbound $outbound,
        private TenantGuard $tenantGuard,
        private EntityManager $entityManager,
        private ChatwootApiClient $chatwootApiClient,
        private Log $log,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('OpenConversationWithNote: missing tenantId.');
        }

        $target = $context->target;
        $this->tenantGuard->assertEntityTenant($target, $tenantId, 'target');

        $params = $context->params;
        $inboxId = trim((string) ($params['chatwootInboxId'] ?? ''));
        $note = trim((string) ($params['note'] ?? ''));

        if ($inboxId === '') {
            throw new Error('OpenConversationWithNote: chatwootInboxId is required.');
        }
        if ($note === '') {
            throw new Error('OpenConversationWithNote: note is required.');
        }
        if (!$context->actor) {
            throw new Error('OpenConversationWithNote: the automation needs a run-as user to write the note.');
        }

        $person = $this->person($target);
        if ($person && $this->outbound->isOptedOut($person)) {
            $this->log->warning("OpenConversationWithNote: {$person->getId()} opted out of WhatsApp, skipping.");

            return;
        }

        $conn = $this->outbound->resolveConnection(
            $inboxId,
            $tenantId,
            JourneyWhatsAppOutbound::CHANNELS_MESSAGE,
            $context->actor,
        );

        $account = $this->entityManager->getEntityById('ChatwootAccount', (string) $conn['inbox']->get('chatwootAccountId'));
        if (!$account) {
            throw new Error('OpenConversationWithNote: Chatwoot account not found.');
        }

        $contactId = $this->chatwootContactId($person ?? $target, $account, $conn);
        if ($contactId === null) {
            $this->log->warning("OpenConversationWithNote: no WhatsApp number for {$target->getEntityType()}/{$target->getId()}, skipping.");

            return;
        }

        $mention = '';
        $aiAgentId = trim((string) ($params['aiAgentId'] ?? ''));
        if ($aiAgentId !== '') {
            $mention = $this->aiMention($aiAgentId, $account) . ' ';
        }

        $token = $this->personToken($context->actor, $account, $conn['platformUrl']);

        $assigneeId = !empty($params['assignToOwner']) ? $this->ownerChatwootUserId($target, $account) : null;

        $response = $this->chatwootApiClient->accountRequest(
            $conn['platformUrl'],
            $token,
            'POST',
            '/api/v1/accounts/' . $conn['externalAccountId'] . '/conversations',
            self::requestBody($contactId, $conn['externalInboxId'], $mention . $note, $assigneeId),
        );

        if ($response['code'] < 200 || $response['code'] >= 300) {
            $detail = $response['body']['message'] ?? $response['body']['error'] ?? '';
            throw new Error(
                'OpenConversationWithNote: Chatwoot refused the conversation (HTTP ' . $response['code'] . ')' .
                (is_string($detail) && $detail !== '' ? ': ' . $detail : '')
            );
        }

        $this->log->info(
            'OpenConversationWithNote: note posted in conversation ' . ($response['body']['id'] ?? '?') .
            " for {$target->getEntityType()}/{$target->getId()}"
        );
    }

    /**
     * With the inbox's single-conversation lock, Chatwoot reuses the person's open conversation and only applies
     * the assignee when it creates a new one.
     *
     * @return array<string, mixed>
     */
    public static function requestBody(int $contactId, int $inboxId, string $content, ?int $assigneeId): array
    {
        return [
            'contact_id' => $contactId,
            'inbox_id' => $inboxId,
            ...($assigneeId ? ['assignee_id' => $assigneeId] : []),
            'message' => ['content' => $content, 'private' => true],
        ];
    }

    /** Same markup the Chatwoot editor writes, so the AI and notifications see a direct user mention. */
    public static function mentionMarkup(int $chatwootUserId, string $name): string
    {
        return '[@' . $name . '](mention://user/' . $chatwootUserId . '/' . rawurlencode($name) . ')';
    }

    /** The person the conversation is with: the Contact itself, or the record's Contact. */
    private function person(Entity $target): ?Entity
    {
        if ($target->getEntityType() === 'Contact') {
            return $target;
        }

        $contactId = $target->hasAttribute('contactId') ? (string) ($target->get('contactId') ?? '') : '';

        return $contactId !== '' ? $this->entityManager->getEntityById('Contact', $contactId) : null;
    }

    /**
     * Prefer the Chatwoot contact the CRM pushed for this Contact, so the AI sees the Contact's deals; fall back to
     * finding or creating one by the record's first mobile number.
     *
     * @param array<string, mixed> $conn
     */
    private function chatwootContactId(Entity $record, Entity $account, array $conn): ?int
    {
        if ($record->getEntityType() === 'Contact') {
            $link = $this->entityManager->getRDBRepository('ChatwootContact')
                ->where(['contactId' => $record->getId(), 'chatwootAccountId' => $account->getId()])
                ->order('createdAt', 'DESC')
                ->findOne();
            $id = (int) ($link?->get('mergedIntoChatwootContactId') ?: $link?->get('chatwootContactId') ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        $phones = $this->outbound->resolvePhones($record);
        if ($phones === []) {
            return null;
        }

        $contact = $this->chatwootApiClient->findOrCreateContact(
            $conn['platformUrl'],
            $conn['accountApiKey'],
            $conn['externalAccountId'],
            $conn['externalInboxId'],
            $phones[0],
            $this->outbound->displayName($record) ?: null,
        );
        $id = (int) ($contact['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function aiMention(string $membershipId, Entity $account): string
    {
        $membership = $this->entityManager->getEntityById('ChatwootAccountUserMembership', $membershipId);
        if (!$membership || !$membership->get('isAI') || $membership->get('chatwootAccountId') !== $account->getId()) {
            throw new Error('OpenConversationWithNote: the AI agent is not an AI on this inbox\'s workspace.');
        }

        $user = $this->entityManager->getEntityById('ChatwootUser', (string) $membership->get('chatwootUserId'));
        $platformUserId = (int) ($user?->get('chatwootUserId') ?? 0);
        if ($platformUserId <= 0) {
            throw new Error('OpenConversationWithNote: the AI agent has no Chatwoot user.');
        }

        $name = trim((string) ($membership->get('availableName') ?: $user->get('name') ?: 'IA'));

        return self::mentionMarkup($platformUserId, $name);
    }

    private function personToken(User $actor, Entity $account, string $platformUrl): string
    {
        $chatwootUser = $this->chatwootUserOf($actor->getId(), $account);
        $token = (string) ($chatwootUser?->get('userAccessToken') ?? '');

        if ($token === '' && $chatwootUser?->get('chatwootUserId')) {
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', (string) $account->get('platformId'));
            $platformToken = (string) ($platform?->get('accessToken') ?? '');
            if ($platformToken !== '') {
                try {
                    $token = (string) $this->chatwootApiClient->fetchUserAccessToken(
                        $platformUrl,
                        $platformToken,
                        (int) $chatwootUser->get('chatwootUserId'),
                    );
                } catch (Throwable $e) {
                    $this->log->warning('OpenConversationWithNote: token fetch failed: ' . $e->getMessage());
                }
            }
        }

        if ($token === '') {
            throw new Error(
                "OpenConversationWithNote: run-as user {$actor->getUserName()} has no Chatwoot login on this workspace."
            );
        }

        return $token;
    }

    private function ownerChatwootUserId(Entity $target, Entity $account): ?int
    {
        $ownerId = $target->hasAttribute('assignedUserId') ? (string) ($target->get('assignedUserId') ?? '') : '';
        if ($ownerId === '') {
            return null;
        }

        $id = (int) ($this->chatwootUserOf($ownerId, $account)?->get('chatwootUserId') ?? 0);

        return $id > 0 ? $id : null;
    }

    private function chatwootUserOf(string $userId, Entity $account): ?Entity
    {
        return $this->entityManager->getRDBRepository('ChatwootUser')
            ->where(['assignedUserId' => $userId, 'platformId' => $account->get('platformId')])
            ->findOne();
    }
}
