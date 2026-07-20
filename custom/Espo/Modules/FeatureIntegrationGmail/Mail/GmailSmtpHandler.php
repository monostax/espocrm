<?php

namespace Espo\Modules\FeatureIntegrationGmail\Mail;

use Espo\Core\Mail\Smtp\Handler;
use Espo\Core\Mail\SmtpParams;
use Espo\Core\Utils\Log;
use Espo\Entities\EmailAccount;
use Espo\Entities\InboundEmail;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\Exceptions\AccountNotFound;
use Espo\Tools\OAuth\Exceptions\NoToken;
use Espo\Tools\OAuth\Exceptions\ProviderNotAvailable;
use Espo\Tools\OAuth\Exceptions\TokenObtainingFailure;
use Espo\Tools\OAuth\TokensProvider;
use RuntimeException;

/**
 * SMTP handler for Gmail via OAuthAccount (XOAUTH2).
 *
 * Wired onto EmailAccount / InboundEmail.smtpHandler by the BeforeSave hooks
 * when an oAuthAccount is linked. Injects a live access token as the SMTP
 * password with auth mechanism xoauth so DefaultTransportPreparator uses
 * Symfony's XOAuth2Authenticator.
 */
class GmailSmtpHandler implements Handler
{
    public function __construct(
        private EntityManager $entityManager,
        private TokensProvider $tokensProvider,
        private Log $log,
    ) {}

    public function handle(SmtpParams $params, ?string $id): SmtpParams
    {
        if ($id === null) {
            return $params;
        }

        $entity = $this->loadAccount($id);

        if ($entity === null) {
            throw new RuntimeException("GmailSmtpHandler: account '$id' not found.");
        }

        $oAuthAccountId = $entity->get('oAuthAccountId');

        if (!$oAuthAccountId) {
            $this->log->warning(
                "GmailSmtpHandler: account '$id' has no oAuthAccountId; using stored credentials."
            );

            return $params;
        }

        try {
            $tokens = $this->tokensProvider->get($oAuthAccountId);
        } catch (AccountNotFound | ProviderNotAvailable | NoToken | TokenObtainingFailure $e) {
            $this->log->error(
                "GmailSmtpHandler: failed to obtain token for OAuthAccount '$oAuthAccountId' " .
                "(account='$id'): " . $e->getMessage()
            );

            throw new RuntimeException(
                "Gmail OAuth token unavailable for account '$id': " . $e->getMessage(),
                0,
                $e
            );
        }

        $emailAddress = $entity->get('emailAddress') ?? $params->getUsername();

        if (!$emailAddress) {
            throw new RuntimeException("GmailSmtpHandler: no email address on account '$id'.");
        }

        return $params
            ->withAuth(true)
            ->withAuthMechanism(SmtpParams::AUTH_MECHANISM_XOAUTH)
            ->withUsername($emailAddress)
            ->withPassword($tokens->getAccessToken());
    }

    private function loadAccount(string $id): EmailAccount|InboundEmail|null
    {
        $emailAccount = $this->entityManager->getEntityById(EmailAccount::ENTITY_TYPE, $id);

        if ($emailAccount instanceof EmailAccount) {
            return $emailAccount;
        }

        $inboundEmail = $this->entityManager->getEntityById(InboundEmail::ENTITY_TYPE, $id);

        if ($inboundEmail instanceof InboundEmail) {
            return $inboundEmail;
        }

        return null;
    }
}
