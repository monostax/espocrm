<?php

namespace Espo\Modules\FeatureIntegrationGmail\Hooks\EmailAccount;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Log;
use Espo\Entities\EmailAccount;
use Espo\Entities\OAuthAccount;
use Espo\Modules\FeatureIntegrationGmail\Mail\GmailImapHandler;
use Espo\Modules\FeatureIntegrationGmail\Mail\GmailSmtpHandler;
use Espo\Modules\FeatureIntegrationGmail\Rebuild\SeedOAuthProviderGmail;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * When an EmailAccount is linked to a Gmail OAuthAccount, wire XOAUTH2 handlers
 * and fill sensible Gmail IMAP/SMTP defaults. Clears handlers when unlinked.
 *
 * @implements BeforeSave<EmailAccount>
 */
class ApplyGmailOAuth implements BeforeSave
{
    public static int $order = 10;

    private const IMAP_HOST = 'imap.gmail.com';
    private const IMAP_PORT = 993;
    private const IMAP_SECURITY = 'SSL';

    private const SMTP_HOST = 'smtp.gmail.com';
    private const SMTP_PORT = 587;
    private const SMTP_SECURITY = 'TLS';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof EmailAccount) {
            return;
        }

        // Only react when the OAuth link changes, or on create with a value set.
        if (
            !$entity->isNew() &&
            !$entity->isAttributeChanged('oAuthAccountId')
        ) {
            // Still ensure handlers stay consistent if already linked
            // (e.g. account re-saved without touching the link).
            if ($entity->get('oAuthAccountId') && $this->isGmailOAuthAccount((string) $entity->get('oAuthAccountId'))) {
                $this->applyHandlers($entity);
            }

            return;
        }

        $oAuthAccountId = $entity->get('oAuthAccountId');

        if (!$oAuthAccountId) {
            $this->clearHandlers($entity);

            return;
        }

        if (!$this->isGmailOAuthAccount((string) $oAuthAccountId)) {
            $this->log->warning(
                "FeatureIntegrationGmail: EmailAccount '{$entity->getId()}' linked to " .
                "non-Gmail OAuthAccount '$oAuthAccountId'; handlers not applied."
            );

            // Still clear Gmail handlers if a different provider was linked after Gmail.
            if (
                $entity->get('imapHandler') === GmailImapHandler::class ||
                $entity->get('smtpHandler') === GmailSmtpHandler::class
            ) {
                $this->clearHandlers($entity);
            }

            return;
        }

        $this->applyHandlers($entity);
        $this->applyGmailDefaults($entity);
    }

    private function applyHandlers(EmailAccount $entity): void
    {
        $entity->set('imapHandler', GmailImapHandler::class);
        $entity->set('smtpHandler', GmailSmtpHandler::class);
    }

    private function clearHandlers(EmailAccount $entity): void
    {
        if (
            $entity->get('imapHandler') === GmailImapHandler::class ||
            $entity->get('imapHandler') === null
        ) {
            $entity->set('imapHandler', null);
        }

        if (
            $entity->get('smtpHandler') === GmailSmtpHandler::class ||
            $entity->get('smtpHandler') === null
        ) {
            $entity->set('smtpHandler', null);
        }
    }

    /**
     * Prefill Gmail hosts/ports/usernames. When the OAuth link changes to Gmail,
     * also replace known Microsoft 365 OAuth hosts so provider switches work.
     */
    private function applyGmailDefaults(EmailAccount $entity): void
    {
        $emailAddress = $entity->get('emailAddress');
        $forceHosts = $entity->isNew() || $entity->isAttributeChanged('oAuthAccountId');

        $host = $entity->get('host');
        if (
            $forceHosts ||
            !$host ||
            $host === self::IMAP_HOST ||
            $host === 'outlook.office365.com'
        ) {
            $entity->set('host', self::IMAP_HOST);
            $entity->set('port', self::IMAP_PORT);
            $entity->set('security', self::IMAP_SECURITY);
        }

        $smtpHost = $entity->get('smtpHost');
        if (
            $forceHosts ||
            !$smtpHost ||
            $smtpHost === self::SMTP_HOST ||
            $smtpHost === 'smtp.office365.com'
        ) {
            $entity->set('smtpHost', self::SMTP_HOST);
            $entity->set('smtpPort', self::SMTP_PORT);
            $entity->set('smtpSecurity', self::SMTP_SECURITY);
            $entity->set('smtpAuth', true);
        }

        if ($emailAddress) {
            if (!$entity->get('username') || $entity->get('username') === $emailAddress) {
                $entity->set('username', $emailAddress);
            }

            if (!$entity->get('smtpUsername') || $entity->get('smtpUsername') === $emailAddress) {
                $entity->set('smtpUsername', $emailAddress);
            }
        }

        // Enable both sides when linking OAuth — user can still turn them off.
        if ($entity->isNew() || $entity->isAttributeChanged('oAuthAccountId')) {
            if ($entity->get('useImap') === null || $entity->get('useImap') === false) {
                // Keep explicit false if user disabled; only force on create.
                if ($entity->isNew()) {
                    $entity->set('useImap', true);
                }
            }

            if ($entity->isNew()) {
                $entity->set('useSmtp', true);
            }
        }

        // OAuth replaces passwords — clear stale secrets so they aren't tested accidentally.
        if ($entity->isAttributeChanged('oAuthAccountId') || $entity->isNew()) {
            $entity->set('password', null);
            $entity->set('smtpPassword', null);
        }
    }

    private function isGmailOAuthAccount(string $oAuthAccountId): bool
    {
        $account = $this->entityManager->getEntityById(OAuthAccount::ENTITY_TYPE, $oAuthAccountId);

        if (!$account instanceof OAuthAccount) {
            return false;
        }

        try {
            $provider = $account->getProvider();
        } catch (\Throwable) {
            return false;
        }

        return $provider->get('provider') === SeedOAuthProviderGmail::PROVIDER_DISCRIMINATOR
            || $provider->getId() === SeedOAuthProviderGmail::PROVIDER_ID;
    }
}
