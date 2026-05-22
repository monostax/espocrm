<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Entities\OAuthAccount;
use Espo\Entities\OAuthProvider;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaFacebookPage;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\TokensProvider;
use Throwable;

/**
 * One-shot synchronization of Pages reachable via a connected OAuthAccount.
 *
 * Triggered by admin pressing "Sync Pages" on a MetaFacebookPage list/detail
 * (controller `MetaFacebookPage::postActionSync`), OR directly after the OAuth
 * callback finishes (future enhancement; for now it's manual).
 *
 * Steps:
 *   1. Load OAuthAccount; reject if its OAuthProvider.provider != 'meta-leadads'.
 *   2. Resolve user access token via Espo\Tools\OAuth\TokensProvider (handles
 *      refresh + decryption).
 *   3. GET /me/accounts → list of pages with per-page tokens.
 *   4. For each Page, upsert MetaFacebookPage by pageId (encrypt token,
 *      stash oAuthAccountId).
 *   5. For new pages, also POST /{pageId}/subscribed_apps (subscribe app to
 *      leadgen field). Failure is non-fatal — admin can retry from UI.
 *
 * Returns a SyncResult-like array for the caller (controller) to render.
 */
class PageSyncService
{
    public function __construct(
        private EntityManager $entityManager,
        private MetaGraphApiClient $graphApiClient,
        private TokensProvider $tokensProvider,
        private \Espo\Core\Utils\Crypt $crypt,
        private Log $log,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   pagesDiscovered: int,
     *   pagesCreated: int,
     *   pagesUpdated: int,
     *   subscribed: int,
     *   subscribeErrors: array<int, array{pageId: string, error: string}>,
     *   error?: string,
     * }
     */
    public function syncForOAuthAccount(string $oAuthAccountId): array
    {
        $result = [
            'ok'              => false,
            'pagesDiscovered' => 0,
            'pagesCreated'    => 0,
            'pagesUpdated'    => 0,
            'subscribed'      => 0,
            'subscribeErrors' => [],
        ];

        $account = $this->entityManager
            ->getEntityById(OAuthAccount::ENTITY_TYPE, $oAuthAccountId);

        if (!$account instanceof OAuthAccount) {
            $result['error'] = 'OAuthAccount not found.';

            return $result;
        }

        $provider = $this->entityManager
            ->getEntityById(OAuthProvider::ENTITY_TYPE, $account->get('providerId'));

        if (!$provider instanceof OAuthProvider || $provider->get('provider') !== 'meta-leadads') {
            $result['error'] = 'OAuthAccount provider is not "meta-leadads".';

            return $result;
        }

        try {
            $tokens = $this->tokensProvider->get($oAuthAccountId);
            $userToken = $tokens->getAccessToken();
        } catch (Throwable $e) {
            $result['error'] = 'Failed to obtain user access token: ' . $e->getMessage();

            return $result;
        }

        if (!$userToken) {
            $result['error'] = 'OAuthAccount has no usable access token.';

            return $result;
        }

        try {
            $pages = $this->graphApiClient->listPages($userToken);
        } catch (Error $e) {
            $result['error'] = $e->getMessage();

            return $result;
        }

        $result['pagesDiscovered'] = count($pages);

        foreach ($pages as $pageData) {
            $pageId = (string) ($pageData['id'] ?? '');
            $name   = (string) ($pageData['name'] ?? '');
            $token  = (string) ($pageData['access_token'] ?? '');

            if ($pageId === '' || $token === '') {
                continue;
            }

            $created = $this->upsertPage($pageId, $name, $token, $oAuthAccountId);

            if ($created) {
                $result['pagesCreated']++;
            } else {
                $result['pagesUpdated']++;
            }

            try {
                $this->graphApiClient->subscribeAppForPage($pageId, $token);
                $this->markSubscribed($pageId, true);
                $result['subscribed']++;
            } catch (Throwable $e) {
                $this->markSubscribed($pageId, false);
                $result['subscribeErrors'][] = [
                    'pageId' => $pageId,
                    'error'  => $e->getMessage(),
                ];
                $this->log->warning(
                    "MetaLeadAds: subscribe failed for page {$pageId}: " . $e->getMessage(),
                );
            }
        }

        $result['ok'] = true;

        return $result;
    }

    /**
     * @return bool True if a new row was created, false if updated.
     */
    private function upsertPage(
        string $pageId,
        string $name,
        string $token,
        string $oAuthAccountId,
    ): bool {
        $existing = $this->entityManager
            ->getRDBRepository(MetaFacebookPage::ENTITY_TYPE)
            ->where(['pageId' => $pageId, 'deleted' => false])
            ->findOne();

        $encrypted = $this->crypt->encrypt($token);
        $now = date('Y-m-d H:i:s');

        if ($existing instanceof MetaFacebookPage) {
            $existing->set('name', $name);
            $existing->set('oAuthAccountId', $oAuthAccountId);
            // We bypass the beforeSave hook here because we're storing
            // already-encrypted ciphertext directly.
            $existing->set('pageAccessToken', $encrypted);
            $existing->set('lastSyncedAt', $now);
            $existing->set('lastSyncError', null);

            $this->entityManager->saveEntity($existing, ['skipHooks' => true, 'silent' => true]);

            return false;
        }

        $page = $this->entityManager->getNewEntity(MetaFacebookPage::ENTITY_TYPE);
        $page->set('name', $name);
        $page->set('pageId', $pageId);
        $page->set('oAuthAccountId', $oAuthAccountId);
        $page->set('pageAccessToken', $encrypted);
        $page->set('isActive', true);
        $page->set('lastSyncedAt', $now);

        $this->entityManager->saveEntity($page, ['skipHooks' => true, 'silent' => true]);

        return true;
    }

    private function markSubscribed(string $pageId, bool $value): void
    {
        $page = $this->entityManager
            ->getRDBRepository(MetaFacebookPage::ENTITY_TYPE)
            ->where(['pageId' => $pageId, 'deleted' => false])
            ->findOne();

        if (!$page instanceof MetaFacebookPage) {
            return;
        }

        $page->set('subscribedToLeadgen', $value);

        try {
            $this->entityManager->saveEntity($page, ['skipHooks' => true, 'silent' => true]);
        } catch (Throwable $e) {
            $this->log->warning('MetaLeadAds: could not flip subscribedToLeadgen flag: ' . $e->getMessage());
        }
    }
}
