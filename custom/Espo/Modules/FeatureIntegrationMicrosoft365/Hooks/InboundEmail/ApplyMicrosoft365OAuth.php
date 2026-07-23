<?php

namespace Espo\Modules\FeatureIntegrationMicrosoft365\Hooks\InboundEmail;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Log;
use Espo\Entities\InboundEmail;
use Espo\Entities\OAuthAccount;
use Espo\Modules\FeatureIntegrationMicrosoft365\Mail\Microsoft365ImapHandler;
use Espo\Modules\FeatureIntegrationMicrosoft365\Mail\Microsoft365SmtpHandler;
use Espo\Modules\FeatureIntegrationMicrosoft365\Rebuild\SeedOAuthProviderMicrosoft365;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * When an InboundEmail (group mailbox) is linked to a Microsoft 365 OAuthAccount,
 * wire XOAUTH2 handlers and fill Office 365 IMAP/SMTP defaults.
 *
 * @implements BeforeSave<InboundEmail>
 */
class ApplyMicrosoft365OAuth implements BeforeSave
{
    public static int $order = 10;

    private const IMAP_HOST = 'outlook.office365.com';
    private const IMAP_PORT = 993;
    private const IMAP_SECURITY = 'SSL';

    private const SMTP_HOST = 'smtp.office365.com';
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
            if ($entity->get('oAuthAccountId') && $this->isMicrosoft365OAuthAccount((string) $entity->get('oAuthAccountId'))) {
                $this->applyHandlers($entity);
            }

            return;
        }

        $oAuthAccountId = $entity->get('oAuthAccountId');

        if (!$oAuthAccountId) {
            $this->clearHandlers($entity);

            return;
        }

        if (!$this->isMicrosoft365OAuthAccount((string) $oAuthAccountId)) {
            $this->log->warning(
                "FeatureIntegrationMicrosoft365: InboundEmail '{$entity->getId()}' linked to " .
                "non-Microsoft 365 OAuthAccount '$oAuthAccountId'; handlers not applied."
            );

            if (
                $entity->get('imapHandler') === Microsoft365ImapHandler::class ||
                $entity->get('smtpHandler') === Microsoft365SmtpHandler::class
            ) {
                $this->clearHandlers($entity);
            }

            return;
        }

        $this->applyHandlers($entity);
        $this->applyMicrosoft365Defaults($entity);
    }

    private function applyHandlers(InboundEmail $entity): void
    {
        $entity->set('imapHandler', Microsoft365ImapHandler::class);
        $entity->set('smtpHandler', Microsoft365SmtpHandler::class);
    }

    private function clearHandlers(InboundEmail $entity): void
    {
        if (
            $entity->get('imapHandler') === Microsoft365ImapHandler::class ||
            $entity->get('imapHandler') === null
        ) {
            $entity->set('imapHandler', null);
        }

        if (
            $entity->get('smtpHandler') === Microsoft365SmtpHandler::class ||
            $entity->get('smtpHandler') === null
        ) {
            $entity->set('smtpHandler', null);
        }
    }

    private function applyMicrosoft365Defaults(InboundEmail $entity): void
    {
        $emailAddress = $entity->get('emailAddress');
        $forceHosts = $entity->isNew() || $entity->isAttributeChanged('oAuthAccountId');

        $host = $entity->get('host');
        if (
            $forceHosts ||
            !$host ||
            $host === self::IMAP_HOST ||
            $host === 'imap.gmail.com'
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
            $smtpHost === 'smtp.gmail.com'
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

        if ($entity->isNew()) {
            $entity->set('useImap', true);
            $entity->set('useSmtp', true);
        }

        if ($entity->isAttributeChanged('oAuthAccountId') || $entity->isNew()) {
            $entity->set('password', null);
            $entity->set('smtpPassword', null);
        }
    }

    private function isMicrosoft365OAuthAccount(string $oAuthAccountId): bool
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

        return $provider->get('provider') === SeedOAuthProviderMicrosoft365::PROVIDER_DISCRIMINATOR
            || $provider->getId() === SeedOAuthProviderMicrosoft365::PROVIDER_ID;
    }
}
