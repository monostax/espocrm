<?php

namespace Espo\Modules\FeatureIntegrationMicrosoft365\Mail;

use Espo\Core\Mail\Account\Storage\Handler;
use Espo\Core\Mail\Account\Storage\Params;
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
 * IMAP storage handler for Microsoft 365 via OAuthAccount (XOAUTH2).
 *
 * Wired onto EmailAccount / InboundEmail.imapHandler by the BeforeSave hooks
 * when an oAuthAccount is linked. Replaces the stored password with a live
 * access token from TokensProvider (refreshing if needed).
 */
class Microsoft365ImapHandler implements Handler
{
    public function __construct(
        private EntityManager $entityManager,
        private TokensProvider $tokensProvider,
        private Log $log,
    ) {}

    public function handle(Params $params, string $id): Params
    {
        $entity = $this->loadAccount($id);

        if ($entity === null) {
            throw new RuntimeException("Microsoft365ImapHandler: account '$id' not found.");
        }

        $oAuthAccountId = $entity->get('oAuthAccountId');

        if (!$oAuthAccountId) {
            $this->log->warning(
                "Microsoft365ImapHandler: account '$id' has no oAuthAccountId; using stored credentials."
            );

            return $params;
        }

        try {
            $tokens = $this->tokensProvider->get($oAuthAccountId);
        } catch (AccountNotFound | ProviderNotAvailable | NoToken | TokenObtainingFailure $e) {
            $this->log->error(
                "Microsoft365ImapHandler: failed to obtain token for OAuthAccount '$oAuthAccountId' " .
                "(account='$id'): " . $e->getMessage()
            );

            throw new RuntimeException(
                "Microsoft 365 OAuth token unavailable for account '$id': " . $e->getMessage(),
                0,
                $e
            );
        }

        $emailAddress = $entity->get('emailAddress') ?? $params->getUsername() ?? $params->getEmailAddress();

        if (!$emailAddress) {
            throw new RuntimeException("Microsoft365ImapHandler: no email address on account '$id'.");
        }

        return $params
            ->withAuthMechanism(Params::AUTH_MECHANISM_XOAUTH)
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
