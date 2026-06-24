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

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaInstagram\Classes\FieldProcessing\OAuthAccount;

use Espo\Core\FieldProcessing\Loader as LoaderInterface;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaInstagram\Services\InstagramGraphApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Computes a real-time, validity-based `connectionStatus` for OAuthAccount.
 *
 * WHY
 * ---
 * The stock `hasAccessToken` flag is `IS_NOT_NULL(accessToken)` — it reports
 * "connected" whenever ANY token string is stored, regardless of whether that
 * token is expired or revoked. That produced the misleading "connected" badge
 * on accounts whose token was actually dead (e.g. antonio.monostax after a
 * Meta session invalidation).
 *
 * This loader derives a truthful status. It is registered as BOTH a read
 * (detail) and list loader:
 *
 *   - LIST context  → cheap LOCAL computation only (token presence +
 *                     providerIsActive + expiresAt vs now). No outbound API
 *                     calls, so opening a 50-row list never fans out into 50
 *                     rate-limited Meta requests.
 *   - DETAIL context → for meta-instagram accounts, a LIVE health check against
 *                     graph.instagram.com gives true real-time validity and
 *                     distinguishes `connected` / `revoked` / `expired`.
 *
 * Non-IG providers fall back to local computation (extend per provider later).
 *
 * SAFETY: never throws. Any failure degrades to `unknown` and logs a warning —
 * one broken account must not break list rendering.
 *
 * Context (list vs detail) is selected by which concrete subclass is
 * registered: {@see ConnectionStatusListLoader} (local-only) is wired to
 * `listLoaderClassNameList`, {@see ConnectionStatusDetailLoader} (live probe)
 * to `readLoaderClassNameList`. EspoCRM's Loader\Params does not expose the
 * list/detail mode, so the split is done by class, not by a runtime flag.
 *
 * @implements LoaderInterface<Entity>
 */
abstract class ConnectionStatusLoader implements LoaderInterface
{
    /** Whether this loader performs a live (outbound) validity probe. */
    abstract protected function isLive(): bool;

    public const STATUS_CONNECTED = 'connected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_PROVIDER_INACTIVE = 'providerInactive';
    public const STATUS_UNKNOWN = 'unknown';

    private const FIELD = 'connectionStatus';

    public function __construct(
        private EntityManager $entityManager,
        private InstagramGraphApiClient $instagramApiClient,
        private Crypt $crypt,
        private Log $log,
    ) {}

    public function process(Entity $entity, Params $params): void
    {
        try {
            $status = $this->computeStatus($entity, !$this->isLive());
        } catch (Throwable $e) {
            $this->log->warning(
                'OAuthAccount ConnectionStatusLoader: failed for ' .
                $entity->getId() . ': ' . $e->getMessage()
            );
            $status = self::STATUS_UNKNOWN;
        }

        $entity->set(self::FIELD, $status);
    }

    private function computeStatus(Entity $entity, bool $localOnly): string
    {
        // Provider disabled → nothing can be connected.
        if (!$entity->get('providerIsActive')) {
            return self::STATUS_PROVIDER_INACTIVE;
        }

        // No token at all → disconnected.
        $encryptedToken = $entity->get('accessToken');

        if (!$encryptedToken) {
            return self::STATUS_DISCONNECTED;
        }

        // Local expiry check (cheap, applies to all contexts).
        $expiresAt = $entity->get('expiresAt');

        if ($expiresAt) {
            $ts = strtotime((string) $expiresAt);

            if ($ts !== false && $ts <= time()) {
                return self::STATUS_EXPIRED;
            }
        }

        // LIST view stops here — local-only, no outbound calls.
        if ($localOnly) {
            return self::STATUS_CONNECTED;
        }

        // DETAIL view → live validity check, per provider.
        $providerType = (string) $entity->get('providerType');

        if ($providerType === 'meta-instagram') {
            return $this->liveInstagramStatus($entity, (string) $encryptedToken);
        }

        // Other providers: no live probe wired yet → trust local computation.
        return self::STATUS_CONNECTED;
    }

    /**
     * Live-probe an Instagram account against graph.instagram.com.
     */
    private function liveInstagramStatus(Entity $entity, string $encryptedToken): string
    {
        // Need the IG business account id to hit /{igId}; without it, fall back.
        $instagramId = $this->resolveInstagramId($entity);

        if ($instagramId === null) {
            return self::STATUS_CONNECTED;
        }

        try {
            $token = $this->crypt->decrypt($encryptedToken);
        } catch (Throwable $e) {
            $this->log->warning('ConnectionStatusLoader: token decrypt failed for ' . $entity->getId());

            return self::STATUS_UNKNOWN;
        }

        if ($token === '') {
            return self::STATUS_DISCONNECTED;
        }

        $httpCode = $this->instagramApiClient->healthCheck($token, $instagramId);

        return match (true) {
            $httpCode === 200 => self::STATUS_CONNECTED,
            // 400/401 with code 190 family → token invalid/expired/revoked.
            in_array($httpCode, [400, 401, 403], true) => self::STATUS_REVOKED,
            default => self::STATUS_UNKNOWN,
        };
    }

    /**
     * Resolve the IG business account id linked to this OAuthAccount via the
     * ChatwootInboxIntegration that references it (the reliable join — the
     * InstagramBusinessAccount link is not a queryable FK column).
     */
    private function resolveInstagramId(Entity $entity): ?string
    {
        $integration = $this->entityManager
            ->getRDBRepository('ChatwootInboxIntegration')
            ->where(['oAuthAccountId' => $entity->getId()])
            ->findOne();

        if ($integration && $integration->get('instagramId')) {
            return (string) $integration->get('instagramId');
        }

        return null;
    }
}
