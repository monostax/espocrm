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
        private TenantResolver $tenantResolver,
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
     *   tenantConflicts: list<string>,
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
            'tenantConflicts' => [],
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

        // Derive teams/tenant once per sync from the OAuthAccount. Each
        // OAuthAccount belongs to one or more teams (FeatureOAuthEnhanced
        // adds this linkMultiple). The Tenant is then derived from those
        // teams via Tenant.baseUserTeam. Pages inherit both.
        //
        // We do this once per sync (not per page) because all pages
        // discovered through this OAuthAccount share the same owner.
        $accountTeamIds = $this->resolveAccountTeamIds($account);
        $accountTenantId = $this->tenantResolver->resolveTenantIdFromTeamIds($accountTeamIds);

        if (empty($accountTeamIds)) {
            $this->log->warning(
                "PageSyncService: OAuthAccount {$oAuthAccountId} has no teams; " .
                "synced pages will not have teams/tenant set and will be invisible to all non-admin users."
            );
        }

        foreach ($pages as $pageData) {
            $pageId = (string) ($pageData['id'] ?? '');
            $name   = (string) ($pageData['name'] ?? '');
            $token  = (string) ($pageData['access_token'] ?? '');

            if ($pageId === '' || $token === '') {
                continue;
            }

            $created = $this->upsertPage(
                $pageId,
                $name,
                $token,
                $oAuthAccountId,
                $accountTeamIds,
                $accountTenantId,
            );

            // Refused: the page row belongs to a different tenant. Skip the
            // subscribe step too — subscribeAppForPage would register this
            // tenant's token against the other tenant's page, and
            // markSubscribed() resolves by pageId and would write to their row.
            if ($created === null) {
                $result['tenantConflicts'][] = $pageId;

                continue;
            }

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
     * Insert/update MetaFacebookPage with explicit team + tenant propagation
     * from the source OAuthAccount.
     *
     * We deliberately keep `skipHooks => true, silent => true` here so that:
     *   - EncryptPageAccessToken does NOT re-encrypt our already-encrypted
     *     ciphertext (that hook has no silent guard).
     *   - AssignTenantFromTeam (silent-guarded) does NOT run, which is fine
     *     because we resolve tenantId explicitly via TenantResolver above.
     *   - No Stream notifications fire for system-internal sync rows.
     *
     * Update semantics:
     *   - Existing pages keep their admin-edited teamsIds/tenantId untouched
     *     UNLESS those are empty (e.g. legacy rows from before this refactor),
     *     in which case we backfill from the OAuthAccount.
     *   - An existing page owned by a DIFFERENT tenant is refused, not
     *     overwritten. `pageId` is globally unique (unique index on
     *     [pageId, deleted]) and is the webhook routing discriminator, so only
     *     one tenant can hold a given Meta page. Overwriting silently replaced
     *     that tenant's oAuthAccountId and pageAccessToken with this one's,
     *     leaving their teams/tenant in place — their lead-ads sync would then
     *     run against a foreign token.
     *
     * @param list<string> $accountTeamIds
     *
     * @return bool|null True if a new row was created, false if updated,
     *         null if refused because the page belongs to another tenant.
     */
    private function upsertPage(
        string $pageId,
        string $name,
        string $token,
        string $oAuthAccountId,
        array $accountTeamIds,
        ?string $accountTenantId,
    ): ?bool {
        $existing = $this->entityManager
            ->getRDBRepository(MetaFacebookPage::ENTITY_TYPE)
            ->where(['pageId' => $pageId, 'deleted' => false])
            ->findOne();

        $encrypted = $this->crypt->encrypt($token);
        $now = date('Y-m-d H:i:s');

        if ($existing instanceof MetaFacebookPage) {
            $existingTenantId = (string) ($existing->get('tenantId') ?? '');
            $incomingTenantId = (string) ($accountTenantId ?? '');

            if (
                $existingTenantId !== ''
                && $incomingTenantId !== ''
                && $existingTenantId !== $incomingTenantId
            ) {
                $this->log->error(sprintf(
                    'MetaLeadAds: refused cross-tenant MetaFacebookPage takeover — page %s is owned by '
                    . 'tenant=%s but OAuthAccount %s resolves to tenant=%s. The Meta page must be '
                    . 'disconnected from the other tenant first.',
                    $pageId,
                    $existingTenantId,
                    $oAuthAccountId,
                    $incomingTenantId,
                ));

                return null;
            }

            $existing->set('name', $name);
            $existing->set('oAuthAccountId', $oAuthAccountId);
            // We bypass the beforeSave hook here because we're storing
            // already-encrypted ciphertext directly.
            $existing->set('pageAccessToken', $encrypted);
            $existing->set('lastSyncedAt', $now);
            $existing->set('lastSyncError', null);

            // Backfill teams/tenant only if missing (don't overwrite admin
            // choices on existing rows).
            $this->backfillTeamsAndTenant($existing, $accountTeamIds, $accountTenantId);

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

        if (!empty($accountTeamIds)) {
            $page->set('teamsIds', $accountTeamIds);
        }

        if ($accountTenantId !== null) {
            $page->set('tenantId', $accountTenantId);
        }

        $this->entityManager->saveEntity($page, ['skipHooks' => true, 'silent' => true]);

        return true;
    }

    /**
     * Read teams from an OAuthAccount.
     *
     * OAuthAccount.teams is provided by FeatureOAuthEnhanced. Defensive
     * against entities loaded without the link multiple list populated.
     *
     * @return list<string>
     */
    private function resolveAccountTeamIds(OAuthAccount $account): array
    {
        try {
            $ids = $account->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $ids = [];
        }

        if (!empty($ids)) {
            return array_values(array_unique($ids));
        }

        $teamsIds = $account->get('teamsIds');

        if (is_array($teamsIds) && !empty($teamsIds)) {
            return array_values(array_unique($teamsIds));
        }

        return [];
    }

    /**
     * Backfill teams/tenant on an existing entity only when it has none.
     *
     * @param list<string> $teamIds
     */
    private function backfillTeamsAndTenant(
        MetaFacebookPage $entity,
        array $teamIds,
        ?string $tenantId,
    ): void {
        if (empty($teamIds)) {
            return;
        }

        $currentTeamIds = [];
        try {
            $currentTeamIds = $entity->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $currentTeamIds = [];
        }

        if (empty($currentTeamIds)) {
            $entity->set('teamsIds', $teamIds);
        }

        if ($tenantId !== null && !$entity->get('tenantId')) {
            $entity->set('tenantId', $tenantId);
        }
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
