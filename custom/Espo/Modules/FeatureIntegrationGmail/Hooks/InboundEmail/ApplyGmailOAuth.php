<?php

namespace Espo\Modules\FeatureIntegrationGmail\Hooks\InboundEmail;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Log;
use Espo\Entities\InboundEmail;
use Espo\Entities\OAuthAccount;
use Espo\Modules\FeatureIntegrationGmail\Mail\GmailImapHandler;
use Espo\Modules\FeatureIntegrationGmail\Mail\GmailSmtpHandler;
use Espo\Modules\FeatureIntegrationGmail\Rebuild\SeedOAuthProviderGmail;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * When an InboundEmail (group mailbox) is linked to a Gmail OAuthAccount,
 * wire XOAUTH2 handlers and fill Gmail IMAP/SMTP defaults.
 *
 * @implements BeforeSave<InboundEmail>
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
        if (!$entity instanceof InboundEmail) {
            return;
        }

        if (
            !$entity->isNew() &&
            !$entity->isAttributeChanged('oAuthAccountId')
        ) {
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
                "FeatureIntegrationGmail: InboundEmail '{$entity->getId()}' linked to " .
                "non-Gmail OAuthAccount '$oAuthAccountId'; handlers not applied."
            );

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

    private function applyHandlers(InboundEmail $entity): void
    {
        $entity->set('imapHandler', GmailImapHandler::class);
        $entity->set('smtpHandler', GmailSmtpHandler::class);
    }

    private function clearHandlers(InboundEmail $entity): void
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

    private function applyGmailDefaults(InboundEmail $entity): void
    {
        $emailAddress = $entity->get('emailAddress');

        if (!$entity->get('host') || $entity->get('host') === self::IMAP_HOST) {
            $entity->set('host', self::IMAP_HOST);
            $entity->set('port', self::IMAP_PORT);
            $entity->set('security', self::IMAP_SECURITY);
        }

        if (!$entity->get('smtpHost') || $entity->get('smtpHost') === self::SMTP_HOST) {
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

        if ($entity->isNew()) {
            $entity->set('useImap', true);
            $entity->set('useSmtp', true);
        }

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
