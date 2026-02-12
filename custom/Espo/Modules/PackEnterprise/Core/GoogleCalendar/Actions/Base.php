<?php

namespace Espo\Modules\PackEnterprise\Core\GoogleCalendar\Actions;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\PackEnterprise\Core\GoogleCalendar\Client\GoogleCalendarClient;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\TokensProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Base action class for Google Calendar operations.
 * Uses TokensProvider + OAuthAccount instead of ClientManager + ExternalAccount.
 */
abstract class Base
{
    protected ?string $userId = null;
    protected ?string $oAuthAccountId = null;
    protected ?GoogleCalendarClient $client = null;

    protected Acl $acl;
    protected EntityManager $entityManager;
    protected AclManager $aclManager;
    protected TokensProvider $tokensProvider;
    protected InjectableFactory $injectableFactory;
    protected Config $config;
    protected Metadata $metadata;
    protected LoggerInterface $log;

    public function __construct(
        EntityManager $entityManager,
        AclManager $aclManager,
        TokensProvider $tokensProvider,
        InjectableFactory $injectableFactory,
        Config $config,
        Metadata $metadata,
        LoggerInterface $log
    ) {
        $this->entityManager = $entityManager;
        $this->aclManager = $aclManager;
        $this->tokensProvider = $tokensProvider;
        $this->injectableFactory = $injectableFactory;
        $this->config = $config;
        $this->metadata = $metadata;
        $this->log = $log;
    }

    protected function setAcl(): void
    {
        /** @var ?User $user */
        $user = $this->entityManager->getEntityById('User', $this->getUserId());

        if (!$user) {
            throw new RuntimeException("No User with id: " . $this->getUserId());
        }

        $this->acl = $this->aclManager->createUserAcl($user);
    }

    public function setUserId(string $userId): void
    {
        $this->userId = $userId;
        $this->client = null;

        $this->setAcl();
    }

    public function setOAuthAccountId(string $oAuthAccountId): void
    {
        $this->oAuthAccountId = $oAuthAccountId;
        $this->client = null;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * Creates a GoogleCalendarClient with a fresh access token from TokensProvider.
     */
    protected function getClient(): GoogleCalendarClient
    {
        if (!$this->client) {
            $this->client = $this->createClient();
        }

        return $this->client;
    }

    /**
     * Invalidate the cached client and create a new one with a fresh token.
     * Call this after a 401 error to force token refresh and retry.
     */
    public function refreshClient(): GoogleCalendarClient
    {
        $this->client = null;
        $this->client = $this->createClient();

        return $this->client;
    }

    /**
     * Create a new GoogleCalendarClient with a fresh access token.
     */
    private function createClient(): GoogleCalendarClient
    {
        if (!$this->oAuthAccountId) {
            throw new RuntimeException("OAuthAccount ID is not set.");
        }

        $tokens = $this->tokensProvider->get($this->oAuthAccountId);

        return new GoogleCalendarClient($tokens->getAccessToken(), $this->log);
    }
}
