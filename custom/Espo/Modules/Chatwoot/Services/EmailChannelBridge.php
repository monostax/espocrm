<?php

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Log;
use Espo\Core\Utils\Crypt;
use Espo\Entities\EmailAccount;
use Espo\Entities\InboundEmail;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Bidirectional bridge between Chatwoot Channel::Email and CRM mailboxes
 * (InboundEmail for group / EmailAccount for personal).
 *
 * Ownership (emailFetchOwner on ChatwootInbox):
 * - chatwoot: Chatwoot polls IMAP; CRM keeps credentials mirrored with useImap=false
 * - crm: CRM owns poll (rare); Chatwoot channel may still have smtp for agent UI
 *
 * Loop prevention: all bridge writes use save option skipEmailChannelBridge.
 */
class EmailChannelBridge
{
    public const SAVE_OPTION_SKIP = 'skipEmailChannelBridge';
    public const OWNER_CHATWOOT = 'chatwoot';
    public const OWNER_CRM = 'crm';
    public const REMOTE_CHANNEL_EMAIL = 'Channel::Email';

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Crypt $crypt,
        private Log $log,
        private ChatwootAccountMembershipOrchestrator $membershipOrchestrator,
    ) {}

    /**
     * Chatwoot → CRM. Called from SyncInboxesFromChatwoot when channel is email.
     *
     * @param array<string, mixed> $chatwootInbox
     * @param array<string> $teamsIds
     */
    public function pullFromChatwoot(
        Entity $localInbox,
        array $chatwootInbox,
        string $espoAccountId,
        array $teamsIds = []
    ): ?Entity {
        $remoteType = $chatwootInbox['channel_type'] ?? null;

        $localInbox->set('remoteChannelType', $remoteType);

        if ($remoteType !== self::REMOTE_CHANNEL_EMAIL) {
            if ($localInbox->isAttributeChanged('remoteChannelType')) {
                $this->entityManager->saveEntity($localInbox, [
                    'silent' => true,
                    self::SAVE_OPTION_SKIP => true,
                ]);
            }

            return null;
        }

        $email = $chatwootInbox['email'] ?? null;

        if (!$email || !is_string($email)) {
            $this->log->debug(
                'EmailChannelBridge: Channel::Email inbox has no email address; skipping mailbox upsert.'
            );
            $this->entityManager->saveEntity($localInbox, [
                'silent' => true,
                self::SAVE_OPTION_SKIP => true,
            ]);

            return null;
        }

        $mailbox = $this->upsertMailboxFromPayload(
            $chatwootInbox,
            $email,
            $teamsIds,
            $localInbox
        );

        $owner = $localInbox->get('emailFetchOwner') ?: self::OWNER_CHATWOOT;

        if ($mailbox->getEntityType() === EmailAccount::ENTITY_TYPE) {
            $localInbox->set('emailAccountId', $mailbox->getId());
            $localInbox->set('inboundEmailId', null);
        } else {
            $localInbox->set('inboundEmailId', $mailbox->getId());
            $localInbox->set('emailAccountId', null);
        }
        $localInbox->set('emailFetchOwner', $owner);
        $localInbox->set('remoteChannelType', self::REMOTE_CHANNEL_EMAIL);

        $this->entityManager->saveEntity($localInbox, [
            'silent' => true,
            self::SAVE_OPTION_SKIP => true,
        ]);

        $this->ensurePersonalMailboxOwnerAccess($mailbox, $localInbox);

        return $mailbox;
    }

    /**
     * CRM → Chatwoot. Create or update remote email inbox from a CRM mailbox.
     *
     * @param EmailAccount|InboundEmail|Entity $mailbox
     */
    public function pushMailboxToChatwoot(Entity $mailbox, Entity $chatwootAccount): ?Entity
    {
        $platform = $this->entityManager->getEntityById(
            'ChatwootPlatform',
            $chatwootAccount->get('platformId')
        );

        if (!$platform) {
            throw new \RuntimeException('ChatwootPlatform not found for account.');
        }

        $platformUrl = $platform->get('backendUrl');
        $apiKey = $chatwootAccount->get('apiKey');
        $remoteAccountId = (int) $chatwootAccount->get('chatwootAccountId');

        if (!$platformUrl || !$apiKey || !$remoteAccountId) {
            throw new \RuntimeException('ChatwootAccount missing URL, API key, or remote id.');
        }

        $payload = $this->buildChatwootChannelPayloadFromMailbox($mailbox);
        $email = $payload['channel']['email'] ?? null;

        if (!$email) {
            throw new \RuntimeException('Mailbox has no email address.');
        }

        $localInbox = $this->findLocalInboxForMailbox($mailbox);

        if ($localInbox && $localInbox->get('chatwootInboxId')) {
            $remoteId = (int) $localInbox->get('chatwootInboxId');
            $updatePayload = $payload;
            unset($updatePayload['channel']['type']);

            $this->apiClient->updateInbox(
                $platformUrl,
                $apiKey,
                $remoteAccountId,
                $remoteId,
                $updatePayload
            );

            $remote = $this->apiClient->getInbox(
                $platformUrl,
                $apiKey,
                $remoteAccountId,
                $remoteId
            ) ?? [];
        } else {
            $remote = $this->apiClient->createEmailInbox(
                $platformUrl,
                $apiKey,
                $remoteAccountId,
                $payload
            );
        }

        $teamsIds = $chatwootAccount->getLinkMultipleIdList('teams');
        $localInbox = $this->upsertLocalInboxFromRemote(
            $remote,
            $chatwootAccount->getId(),
            $teamsIds,
            $mailbox
        );

        $localInbox->set('emailFetchOwner', self::OWNER_CHATWOOT);
        $localInbox->set('remoteChannelType', self::REMOTE_CHANNEL_EMAIL);

        if ($mailbox instanceof EmailAccount || $mailbox->getEntityType() === EmailAccount::ENTITY_TYPE) {
            $localInbox->set('emailAccountId', $mailbox->getId());
            $localInbox->set('inboundEmailId', null);
        } else {
            $localInbox->set('inboundEmailId', $mailbox->getId());
            $localInbox->set('emailAccountId', null);
        }

        $this->entityManager->saveEntity($localInbox, [
            'silent' => true,
            self::SAVE_OPTION_SKIP => true,
        ]);

        // Chatwoot owns IMAP after push so CRM does not dual-fetch.
        $this->disableCrmImapFetch($mailbox);

        $this->ensurePersonalMailboxOwnerAccess($mailbox, $localInbox);

        return $localInbox;
    }

    /**
     * Push from ChatwootInboxIntegration email activation.
     */
    public function pushFromIntegration(Entity $integration): Entity
    {
        $account = $this->entityManager->getEntityById(
            'ChatwootAccount',
            $integration->get('chatwootAccountId')
        );

        if (!$account) {
            throw new \RuntimeException('ChatwootAccount not set on integration.');
        }

        $mailbox = $this->loadMailboxFromIntegration($integration);

        if (!$mailbox) {
            throw new \RuntimeException(
                'Email integration requires inboundEmail or emailAccount to be set.'
            );
        }

        $localInbox = $this->pushMailboxToChatwoot($mailbox, $account);

        if (!$localInbox) {
            throw new \RuntimeException('Failed to provision Chatwoot email inbox.');
        }

        $integration->set('chatwootInboxId', $localInbox->get('chatwootInboxId'));
        $integration->set('status', 'ACTIVE');
        $integration->set('connectedAt', date('Y-m-d H:i:s'));
        $integration->set('errorMessage', null);

        $this->entityManager->saveEntity($integration, ['silent' => true]);

        $localInbox->set('chatwootInboxIntegrationId', $integration->getId());
        $this->entityManager->saveEntity($localInbox, [
            'silent' => true,
            self::SAVE_OPTION_SKIP => true,
        ]);

        return $localInbox;
    }

    /**
     * AfterSave hook path: if mailbox is linked to a Chatwoot inbox, push credential deltas.
     *
     * @param EmailAccount|InboundEmail|Entity $mailbox
     */
    public function pushLinkedMailboxIfNeeded(Entity $mailbox): void
    {
        $localInbox = $this->findLocalInboxForMailbox($mailbox);

        if (!$localInbox || !$localInbox->get('chatwootInboxId')) {
            return;
        }

        $account = $this->entityManager->getEntityById(
            'ChatwootAccount',
            $localInbox->get('chatwootAccountId')
        );

        if (!$account) {
            return;
        }

        try {
            $this->pushMailboxToChatwoot($mailbox, $account);
        } catch (\Throwable $e) {
            $this->log->error(
                'EmailChannelBridge: push after mailbox save failed for ' .
                $mailbox->getEntityType() . ' ' . $mailbox->getId() . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * @param array<string, mixed> $chatwootInbox
     * @param array<string> $teamsIds
     */
    private function upsertMailboxFromPayload(
        array $chatwootInbox,
        string $email,
        array $teamsIds,
        Entity $localInbox
    ): Entity {
        $inboundEmailId = $localInbox->get('inboundEmailId');
        $emailAccountId = $localInbox->get('emailAccountId');

        if ($inboundEmailId && $emailAccountId) {
            throw new \RuntimeException(
                "ChatwootInbox {$localInbox->getId()} cannot link both InboundEmail and EmailAccount."
            );
        }

        if ($emailAccountId) {
            return $this->upsertEmailAccountFromPayload($chatwootInbox, $email, $emailAccountId);
        }

        return $this->upsertInboundEmailFromPayload($chatwootInbox, $email, $teamsIds, $localInbox);
    }

    /**
     * @param array<string, mixed> $chatwootInbox
     */
    private function upsertInboundEmailFromPayload(
        array $chatwootInbox,
        string $email,
        array $teamsIds,
        Entity $localInbox
    ): Entity {
        $existingId = $localInbox->get('inboundEmailId');
        $entity = $existingId
            ? $this->entityManager->getEntityById(InboundEmail::ENTITY_TYPE, $existingId)
            : null;

        if (!$entity) {
            $entity = $this->entityManager->getNewEntity(InboundEmail::ENTITY_TYPE);
            $entity->set('emailAddress', $email);
            $entity->set('name', $chatwootInbox['name'] ?? $email);
            $entity->set('status', 'Active');
        }

        $this->applyChatwootPayloadToMailbox($entity, $chatwootInbox, $email);

        // Default: Chatwoot owns fetch (CRM mirror only).
        $entity->set('useImap', false);

        if (!empty($teamsIds) && $entity->hasAttribute('teamsIds')) {
            // InboundEmail uses team (singular) + teams multi depending on version;
            // Set teams when available; group email often has teamId.
            if ($entity->hasAttribute('teamsIds')) {
                $entity->set('teamsIds', $teamsIds);
            }
        }

        if (!empty($teamsIds) && !$entity->get('teamId')) {
            $entity->set('teamId', $teamsIds[0]);
        }

        $this->entityManager->saveEntity($entity, [
            'silent' => true,
            self::SAVE_OPTION_SKIP => true,
        ]);

        return $entity;
    }

    /**
     * A personal mailbox can only be created with an owning user. Pulls from
     * Chatwoot therefore reuse an explicitly linked EmailAccount and otherwise
     * create an account-level InboundEmail instead.
     *
     * @param array<string, mixed> $chatwootInbox
     */
    private function upsertEmailAccountFromPayload(
        array $chatwootInbox,
        string $email,
        string $emailAccountId
    ): Entity {
        $entity = $this->entityManager->getEntityById(EmailAccount::ENTITY_TYPE, $emailAccountId);

        if (!$entity) {
            throw new \RuntimeException("Linked EmailAccount {$emailAccountId} was not found.");
        }

        $this->applyChatwootPayloadToMailbox($entity, $chatwootInbox, $email);
        $entity->set('useImap', false);
        $this->entityManager->saveEntity($entity, [
            'silent' => true,
            self::SAVE_OPTION_SKIP => true,
        ]);

        return $entity;
    }

    /**
     * @param array<string, mixed> $chatwootInbox
     */
    private function applyChatwootPayloadToMailbox(
        Entity $entity,
        array $chatwootInbox,
        string $email
    ): void {
        $entity->set('emailAddress', $email);

        $imapLogin = $chatwootInbox['imap_login'] ?? $email;
        $imapHost = $chatwootInbox['imap_address'] ?? null;
        $imapPort = $chatwootInbox['imap_port'] ?? null;
        $imapSsl = $chatwootInbox['imap_enable_ssl'] ?? true;
        $imapPassword = $chatwootInbox['imap_password'] ?? null;

        if ($imapHost) {
            $entity->set('host', $imapHost);
        }

        if ($imapPort) {
            $entity->set('port', (int) $imapPort);
        }

        $entity->set('security', $imapSsl ? 'SSL' : '');
        $entity->set('username', $imapLogin ?: $email);

        // Only overwrite password when Chatwoot returns a non-empty value
        // (admin token). Empty means redacted or OAuth-only.
        if (is_string($imapPassword) && $imapPassword !== '') {
            $entity->set('password', $this->crypt->encrypt($imapPassword));
        }

        $smtpEnabled = $chatwootInbox['smtp_enabled'] ?? false;
        $smtpHost = $chatwootInbox['smtp_address'] ?? null;
        $smtpPort = $chatwootInbox['smtp_port'] ?? null;
        $smtpLogin = $chatwootInbox['smtp_login'] ?? $email;
        $smtpPassword = $chatwootInbox['smtp_password'] ?? null;
        $smtpStartTls = $chatwootInbox['smtp_enable_starttls_auto'] ?? true;
        $smtpSsl = $chatwootInbox['smtp_enable_ssl_tls'] ?? false;

        if ($smtpEnabled || $smtpHost) {
            $entity->set('useSmtp', true);

            if ($smtpHost) {
                $entity->set('smtpHost', $smtpHost);
            }

            if ($smtpPort) {
                $entity->set('smtpPort', (int) $smtpPort);
            }

            $entity->set('smtpAuth', true);
            $entity->set('smtpUsername', $smtpLogin ?: $email);

            if ($smtpSsl) {
                $entity->set('smtpSecurity', 'SSL');
            } elseif ($smtpStartTls) {
                $entity->set('smtpSecurity', 'TLS');
            } else {
                $entity->set('smtpSecurity', '');
            }

            if (is_string($smtpPassword) && $smtpPassword !== '') {
                $entity->set('smtpPassword', $this->crypt->encrypt($smtpPassword));
            }
        }

        $provider = $chatwootInbox['provider'] ?? null;

        if ($provider === 'google' && !$imapHost) {
            $entity->set('host', 'imap.gmail.com');
            $entity->set('port', 993);
            $entity->set('security', 'SSL');
            $entity->set('smtpHost', 'smtp.gmail.com');
            $entity->set('smtpPort', 587);
            $entity->set('smtpSecurity', 'TLS');
            $entity->set('useSmtp', true);
        }

        if ($provider === 'microsoft' && !$imapHost) {
            $entity->set('host', 'outlook.office365.com');
            $entity->set('port', 993);
            $entity->set('security', 'SSL');
            $entity->set('smtpHost', 'smtp.office365.com');
            $entity->set('smtpPort', 587);
            $entity->set('smtpSecurity', 'TLS');
            $entity->set('useSmtp', true);
        }
    }

    /**
     * @return array{name: string, channel: array<string, mixed>}
     */
    private function buildChatwootChannelPayloadFromMailbox(Entity $mailbox): array
    {
        $email = (string) ($mailbox->get('emailAddress') ?? '');
        $name = (string) ($mailbox->get('name') ?: $email);

        $imapSecurity = $mailbox->get('security');
        $smtpSecurity = $mailbox->get('smtpSecurity');

        $channel = [
            'type' => 'email',
            'email' => $email,
            'imap_enabled' => true,
            'imap_login' => $mailbox->get('username') ?: $email,
            'imap_address' => $mailbox->get('host') ?: '',
            'imap_port' => (int) ($mailbox->get('port') ?: 993),
            'imap_enable_ssl' => $imapSecurity === 'SSL' || $imapSecurity === 'SSL/TLS',
        ];

        $password = $this->decryptMailboxSecret($mailbox->get('password'), 'IMAP password');

        if ($password !== null) {
            $channel['imap_password'] = $password;
        }

        $useSmtp = $mailbox->get('useSmtp');

        if ($useSmtp || $mailbox->get('smtpHost')) {
            $channel['smtp_enabled'] = true;
            $channel['smtp_login'] = $mailbox->get('smtpUsername') ?: $email;
            $channel['smtp_address'] = $mailbox->get('smtpHost') ?: '';
            $channel['smtp_port'] = (int) ($mailbox->get('smtpPort') ?: 587);
            $channel['smtp_enable_ssl_tls'] = $smtpSecurity === 'SSL' || $smtpSecurity === 'SSL/TLS';
            $channel['smtp_enable_starttls_auto'] = $smtpSecurity === 'TLS' || $smtpSecurity === 'STARTTLS';
            $channel['smtp_authentication'] = 'login';

            $smtpPassword = $this->decryptMailboxSecret($mailbox->get('smtpPassword'), 'SMTP password');

            if ($smtpPassword !== null) {
                $channel['smtp_password'] = $smtpPassword;
            }
        }

        // Chatwoot cannot use CRM-side OAuthAccount tokens. Gmail / Microsoft 365
        // OAuth mailboxes must be authorized in Chatwoot first (provider + tokens
        // in provider_config) and then mirrored back via SyncEmailOAuthCredentials.
        // Pushing hosts alone leaves Chatwoot on password IMAP with an empty
        // password → AUTHENTICATE failed.
        $host = (string) ($mailbox->get('host') ?? '');
        $requiresChatwootOAuth = str_contains($host, 'gmail.com')
            || str_contains($host, 'office365.com')
            || str_contains($host, 'outlook.office.com');

        if ($requiresChatwootOAuth && empty($channel['imap_password'])) {
            $providerLabel = str_contains($host, 'gmail.com') ? 'Google' : 'Microsoft';

            throw new \RuntimeException(
                "Chatwoot email inboxes require their own {$providerLabel} OAuth authorization. " .
                "Create the {$providerLabel} inbox in Chatwoot first (or set an IMAP app password), " .
                'then sync it to CRM. CRM-side OAuth alone is not enough for Chatwoot IMAP fetch.'
            );
        }

        return [
            'name' => $name,
            'channel' => $channel,
        ];
    }

    private function findLocalInboxForMailbox(Entity $mailbox): ?Entity
    {
        $entityType = $mailbox->getEntityType();

        if ($entityType === EmailAccount::ENTITY_TYPE) {
            return $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->where(['emailAccountId' => $mailbox->getId()])
                ->findOne();
        }

        if ($entityType === InboundEmail::ENTITY_TYPE) {
            return $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->where(['inboundEmailId' => $mailbox->getId()])
                ->findOne();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $remote
     * @param array<string> $teamsIds
     */
    private function upsertLocalInboxFromRemote(
        array $remote,
        string $espoAccountId,
        array $teamsIds,
        Entity $mailbox
    ): Entity {
        $remoteId = (int) ($remote['id'] ?? 0);

        if (!$remoteId) {
            throw new \RuntimeException('Chatwoot email inbox response missing id.');
        }

        $inbox = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where([
                'chatwootInboxId' => $remoteId,
                'chatwootAccountId' => $espoAccountId,
            ])
            ->findOne();

        if (!$inbox) {
            $inbox = $this->entityManager->getNewEntity('ChatwootInbox');
            $inbox->set('chatwootInboxId', $remoteId);
            $inbox->set('chatwootAccountId', $espoAccountId);
        }

        $inbox->set('name', $remote['name'] ?? ('Email #' . $remoteId));
        $inbox->set('remoteChannelType', $remote['channel_type'] ?? self::REMOTE_CHANNEL_EMAIL);
        $inbox->set('provider', $remote['provider'] ?? null);
        $inbox->set('lastSyncedAt', date('Y-m-d H:i:s'));

        if (!empty($teamsIds)) {
            $inbox->set('teamsIds', $teamsIds);
        }

        return $inbox;
    }

    private function loadMailboxFromIntegration(Entity $integration): ?Entity
    {
        $inboundId = $integration->get('inboundEmailId');
        $emailAccountId = $integration->get('emailAccountId');

        if ($inboundId && $emailAccountId) {
            throw new \RuntimeException(
                'Email integration cannot link both inboundEmail and emailAccount.'
            );
        }

        if ($inboundId) {
            $entity = $this->entityManager->getEntityById(InboundEmail::ENTITY_TYPE, $inboundId);

            if ($entity) {
                return $entity;
            }
        }

        if ($emailAccountId) {
            return $this->entityManager->getEntityById(EmailAccount::ENTITY_TYPE, $emailAccountId);
        }

        return null;
    }

    private function disableCrmImapFetch(Entity $mailbox): void
    {
        if ($mailbox->get('useImap') === false) {
            return;
        }

        $mailbox->set('useImap', false);
        $this->entityManager->saveEntity($mailbox, [
            'silent' => true,
            self::SAVE_OPTION_SKIP => true,
        ]);
    }

    /**
     * Personal EmailAccount must expose CRM conversations/SSO in Chatwoot.
     * Ensure assignedUser is an account agent, then link them (+ AI agents)
     * on the inbox so SyncInboxMembership pushes inbox_members.
     *
     * Best-effort: failures are logged and never abort mailbox bridge.
     */
    private function ensurePersonalMailboxOwnerAccess(Entity $mailbox, Entity $localInbox): void
    {
        if ($mailbox->getEntityType() !== EmailAccount::ENTITY_TYPE) {
            return;
        }

        $assignedUserId = $mailbox->get('assignedUserId');

        if (!$assignedUserId) {
            $this->log->warning(
                'EmailChannelBridge: EmailAccount ' . $mailbox->getId() .
                ' has no assignedUser; cannot grant inbox agent access for ChatwootInbox ' .
                $localInbox->getId()
            );

            return;
        }

        $accountId = $localInbox->get('chatwootAccountId');

        if (!$accountId) {
            return;
        }

        try {
            $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
            $user = $this->entityManager->getEntityById('User', $assignedUserId);

            if (!$account || !$user) {
                $this->log->warning(
                    'EmailChannelBridge: missing ChatwootAccount or User for personal mailbox ' .
                    $mailbox->getId()
                );

                return;
            }

            $ownerMembership = $this->membershipOrchestrator->ensureUserMembership(
                $account,
                $user,
                'agent'
            );

            $repository = $this->entityManager->getRDBRepository('ChatwootInbox');
            $membershipsRelation = $repository->getRelation($localInbox, 'accountUserMemberships');

            if (!$membershipsRelation->isRelatedById($ownerMembership->getId())) {
                $membershipsRelation->relateById($ownerMembership->getId());

                $this->log->info(
                    'EmailChannelBridge: linked personal mailbox owner membership ' .
                    $ownerMembership->getId() . ' to inbox ' . $localInbox->getId()
                );
            }

            $aiMemberships = $this->entityManager
                ->getRDBRepository('ChatwootAccountUserMembership')
                ->where([
                    'chatwootAccountId' => $accountId,
                    'isAI' => true,
                ])
                ->find();

            foreach ($aiMemberships as $membership) {
                if (!$membershipsRelation->isRelatedById($membership->getId())) {
                    $membershipsRelation->relateById($membership->getId());
                }
            }
        } catch (\Throwable $e) {
            $this->log->warning(
                'EmailChannelBridge: failed to grant personal mailbox owner access for inbox ' .
                $localInbox->getId() . ': ' . $e->getMessage()
            );
        }
    }

    private function decryptMailboxSecret(mixed $encryptedSecret, string $field): ?string
    {
        if ($encryptedSecret === null || $encryptedSecret === '') {
            return null;
        }

        if (!is_string($encryptedSecret)) {
            throw new \RuntimeException("CRM {$field} is not a string.");
        }

        try {
            $secret = $this->crypt->decrypt($encryptedSecret);
        } catch (\Throwable $e) {
            throw new \RuntimeException("CRM {$field} could not be decrypted.", 0, $e);
        }

        return $secret === '' ? null : $secret;
    }
}
