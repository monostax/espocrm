<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMetaInstagram\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\Collection as RecordCollection;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppOAuthHelper;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\TokensProvider;
use stdClass;

/**
 * Virtual RecordService for InstagramBusinessAccount entity.
 *
 * Fetches Instagram Business Account data from the Meta Instagram Graph API.
 * Uses OAuthAccount entities (with provider=meta-instagram) for authentication.
 *
 * Instagram Login tokens map 1:1 to a single Instagram Business Account, so
 * the "discovery" step per OAuthAccount returns exactly one record (via /me).
 * We keep the list-shape to stay consistent with WhatsAppBusinessAccount.
 */
class InstagramBusinessAccount
{
    public const ENTITY_TYPE = 'InstagramBusinessAccount';
    private const PROVIDER = 'meta-instagram';

    public function __construct(
        private EntityManager $entityManager,
        private InstagramGraphApiClient $apiClient,
        private TokensProvider $tokensProvider,
        private WhatsAppOAuthHelper $oAuthHelper,
        private Log $log,
        private Acl $acl,
    ) {}

    /**
     * Find Instagram Business Accounts from one, many, or all meta-instagram OAuthAccounts.
     *
     * @param string|null $oAuthAccountId If provided, fetches from this OAuthAccount only.
     * @param string[]|null $oAuthAccountIds If provided, fetches from these OAuthAccounts.
     *                                       Takes precedence over $oAuthAccountId when both are set.
     *                                       When neither is provided, iterates all accessible meta-instagram accounts.
     * @return RecordCollection
     * @throws Error
     * @throws Forbidden
     */
    public function find(?string $oAuthAccountId = null, ?array $oAuthAccountIds = null): RecordCollection
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to InstagramBusinessAccount.");
        }

        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
        $totalCount = 0;

        // When the caller explicitly asks about ONE OAuthAccount (e.g. the
        // ChatwootInboxIntegration channel-creation form), per-account failures
        // are actionable user errors: silencing them hides root causes like
        // "Instagram account is not Professional". Surface them. When the
        // caller asks about MANY (bulk listing), we keep the original
        // best-effort behaviour so one broken account doesn't hide the rest.
        $singleAccountMode = $oAuthAccountId !== null && !$oAuthAccountIds;

        if ($oAuthAccountIds) {
            $oAuthAccounts = [];
            foreach ($oAuthAccountIds as $id) {
                try {
                    $oAuthAccounts[] = $this->oAuthHelper->validateOAuthAccountAccess($id);
                } catch (NotFound $e) {
                    $this->log->warning(
                        "InstagramBusinessAccount: OAuthAccount {$id} not found, skipping."
                    );
                }
            }
        } elseif ($oAuthAccountId) {
            try {
                $oAuthAccounts = [$this->oAuthHelper->validateOAuthAccountAccess($oAuthAccountId)];
            } catch (NotFound $e) {
                $this->log->warning(
                    "InstagramBusinessAccount: OAuthAccount {$oAuthAccountId} not found, returning empty result."
                );
                $oAuthAccounts = [];
            }
        } else {
            $oAuthAccounts = $this->oAuthHelper->getAccessibleOAuthAccounts(self::PROVIDER);
        }

        foreach ($oAuthAccounts as $oAuthAccount) {
            $accountId = $oAuthAccount->getId();
            $accountName = $oAuthAccount->get('name');

            try {
                $tokens = $this->tokensProvider->get($accountId);
            } catch (\Throwable $e) {
                $msg = "Failed to decrypt the access token for Meta (Instagram) "
                    . "OAuth Account '{$accountName}'. The record may be corrupted — "
                    . "delete it and re-authorize. (Original: " . $e->getMessage() . ")";

                $this->log->warning(
                    "InstagramBusinessAccount: Failed to get tokens for OAuthAccount {$accountId}: " . $e->getMessage()
                );

                if ($singleAccountMode) {
                    throw new Error($msg);
                }

                continue;
            }

            $accessToken = $tokens->getAccessToken();

            if (!$accessToken) {
                $msg = "The Meta (Instagram) OAuth Account '{$accountName}' has no "
                    . "access token. Re-authorize it to obtain a fresh token.";

                $this->log->warning(
                    "InstagramBusinessAccount: OAuthAccount {$accountId} has no access token, skipping."
                );

                if ($singleAccountMode) {
                    throw new Error($msg);
                }

                continue;
            }

            try {
                $accounts = $this->apiClient->discoverBusinessAccounts($accessToken);

                foreach ($accounts as $accountData) {
                    $entity = $this->mapAccountToEntity($accountData, $accountId, $accountName);
                    $collection->append($entity);
                    $totalCount++;
                }
            } catch (Error $e) {
                $this->log->error(
                    "InstagramBusinessAccount: Failed to discover accounts for OAuthAccount {$accountId}: " . $e->getMessage()
                );

                // In single-account mode propagate so the channel-creation UI
                // can render the actionable error (e.g. "IG account is not
                // Professional" — translated in InstagramGraphApiClient).
                if ($singleAccountMode) {
                    throw $e;
                }
            }
        }

        return RecordCollection::create($collection, $totalCount);
    }

    /**
     * Read a single Instagram Business Account.
     *
     * @param string $oAuthAccountId
     * @param string $instagramId The `user_id` from /me.
     * @return stdClass Entity value map
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function read(string $oAuthAccountId, string $instagramId): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to InstagramBusinessAccount.");
        }

        $oAuthAccount = $this->oAuthHelper->validateOAuthAccountAccess($oAuthAccountId);
        $accountName = $oAuthAccount->get('name');

        try {
            $tokens = $this->tokensProvider->get($oAuthAccountId);
        } catch (\Throwable $e) {
            throw new Error("Failed to get OAuth tokens: " . $e->getMessage());
        }

        $accessToken = $tokens->getAccessToken();

        if (!$accessToken) {
            throw new Error("OAuthAccount is missing an access token.");
        }

        try {
            $me = $this->apiClient->getMe($accessToken);
        } catch (Error $e) {
            throw new NotFound("Instagram Business Account '{$instagramId}' not found or inaccessible.");
        }

        $candidateId = (string) ($me['user_id'] ?? '');

        if ($candidateId !== $instagramId) {
            throw new NotFound("Instagram Business Account '{$instagramId}' does not match this OAuthAccount.");
        }

        $entity = $this->mapAccountToEntity($me, $oAuthAccountId, $accountName);

        return $entity->getValueMap();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapAccountToEntity(
        array $data,
        string $oAuthAccountId,
        ?string $oAuthAccountName = null,
    ): Entity {
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        // user_id is the Instagram-scoped ID used by webhooks. Fallback to id.
        $instagramId = (string) ($data['user_id'] ?? $data['id'] ?? '');
        $username = $data['username'] ?? '';

        // Composite ID: oAuthAccountId_instagramId
        $entity->set('id', $oAuthAccountId . '_' . $instagramId);
        $entity->set('name', $username !== '' ? $username : ($data['name'] ?? ''));
        $entity->set('instagramId', $instagramId);
        $entity->set('username', $username);
        $entity->set('accountType', $data['account_type'] ?? null);
        $entity->set('profilePictureUrl', $data['profile_picture_url'] ?? null);
        $entity->set('oAuthAccountId', $oAuthAccountId);
        $entity->set('oAuthAccountName', $oAuthAccountName);

        $entity->setAsFetched();

        return $entity;
    }
}
