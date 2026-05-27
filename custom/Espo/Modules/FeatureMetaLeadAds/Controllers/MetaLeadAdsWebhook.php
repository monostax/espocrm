<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Entities\OAuthProvider;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadgenEvent;
use Espo\Modules\FeatureMetaLeadAds\Jobs\IngestLeadgen;
use Espo\ORM\EntityManager;
use stdClass;
use Throwable;

/**
 * Public webhook endpoint for Meta Lead Ads.
 *
 * Routes (both noAuth, defined in Resources/routes.json):
 *   GET  /MetaLeadAds/webhook/:providerId   — verify handshake (preferred, BYOA).
 *   POST /MetaLeadAds/webhook/:providerId   — leadgen delivery (preferred, BYOA).
 *   GET  /MetaLeadAds/webhook               — legacy global handshake.
 *   POST /MetaLeadAds/webhook               — legacy global delivery (try-all-secrets).
 *
 * URL registered in the Meta App dashboard:
 *   - Per-provider BYOA: https://{host}/api/v1/MetaLeadAds/webhook/{oauthProviderId}
 *   - Legacy global:     https://{host}/api/v1/MetaLeadAds/webhook
 *
 * Provider resolution:
 *   1. If `:providerId` is in the path, resolve that exact OAuthProvider row.
 *      This is the BYOA path — each tenant's Meta App points at its own URL,
 *      and each App's HMAC secret is verified against THAT provider's
 *      clientSecret. No ambiguity, no cross-tenant signature confusion.
 *   2. If `:providerId` is absent (legacy), iterate every active meta-leadads
 *      provider and accept the first whose secret validates the signature.
 *      This is the "try-all-secrets" pattern used by the WhatsApp/Instagram
 *      modules. It allows multiple Meta Apps to share one legacy URL during
 *      a transition window — but admins should migrate to BYOA URLs ASAP.
 *
 * SECURITY — POST verification:
 *   X-Hub-Signature-256 = "sha256=" + hex(HMAC-SHA256(rawBody, appSecret))
 *
 * SECURITY — GET handshake:
 *   ?hub.mode=subscribe&hub.verify_token={token}&hub.challenge=...
 *   Token must match the resolved provider's webhookVerifyToken (decrypted).
 *
 * Response strategy (POST):
 *   Always 200 on auth/parsing success — Meta retries aggressively on 5xx
 *   and we'd duplicate work. We persist a MetaLeadgenEvent (status=Received)
 *   per change and enqueue an IngestLeadgen job. Permanent failures inside
 *   the job mark the event row as Failed, surfaced in the CRM UI.
 *
 * Returns:
 *   400 — malformed body
 *   401 — bad/missing X-Hub-Signature-256
 *   403 — bad verify_token (GET handshake)
 *   200 — accepted
 */
class MetaLeadAdsWebhook
{
    private const PROVIDER_DISCRIMINATOR = 'meta-leadads';

    public function __construct(
        private EntityManager $entityManager,
        private JobSchedulerFactory $jobSchedulerFactory,
        private Crypt $crypt,
        private Log $log,
    ) {}

    /**
     * Meta calls this once when the admin (re)subscribes the webhook URL.
     *
     * If verify token matches, MUST echo the challenge as plain text.
     */
    public function getActionWebhook(Request $request, Response $response): void
    {
        $mode      = (string) ($request->getQueryParam('hub_mode')         ?? '');
        $token     = (string) ($request->getQueryParam('hub_verify_token') ?? '');
        $challenge = (string) ($request->getQueryParam('hub_challenge')    ?? '');

        if ($mode !== 'subscribe') {
            $response->setStatus(403);

            return;
        }

        $providerId = $this->extractProviderId($request);

        // For the GET handshake we need ONE provider to compare verify_token
        // against. With the BYOA path that's unambiguous. For the legacy bare
        // path we iterate all active providers and accept any matching token.
        $providers = $providerId !== null
            ? array_filter([$this->resolveProviderById($providerId)])
            : $this->resolveAllActiveProviders();

        if (empty($providers)) {
            $this->log->error('MetaLeadAds: webhook verify failed — no active provider found.', [
                'providerId' => $providerId,
            ]);
            $response->setStatus(403);

            return;
        }

        foreach ($providers as $provider) {
            $expectedToken = $this->decryptVerifyToken($provider);

            if ($expectedToken === null || $expectedToken === '') {
                continue;
            }

            if (hash_equals($expectedToken, $token)) {
                $response->setStatus(200);
                $response->setHeader('Content-Type', 'text/plain');
                $response->writeBody($challenge);

                return;
            }
        }

        $this->log->warning('MetaLeadAds: webhook verify failed — token mismatch.', [
            'providerId' => $providerId,
            'providersTried' => count($providers),
        ]);
        $response->setStatus(403);
    }

    /**
     * Live leadgen delivery.
     */
    public function postActionWebhook(Request $request, Response $response): stdClass
    {
        $response->setHeader('Content-Type', 'application/json');

        try {
            $rawBody = (string) $request->getBodyContents();

            if ($rawBody === '') {
                $response->setStatus(400);

                return (object) ['ok' => false, 'message' => 'Empty body.'];
            }

            $providerId = $this->extractProviderId($request);

            $provider = $providerId !== null
                ? $this->verifySignatureForProvider($providerId, $rawBody, $request)
                : $this->verifySignatureTryAll($rawBody, $request);

            if ($provider === null) {
                $response->setStatus(401);

                return (object) ['ok' => false, 'message' => 'Signature verification failed.'];
            }

            $payload = json_decode($rawBody);

            if (!$payload instanceof stdClass) {
                $response->setStatus(400);

                return (object) ['ok' => false, 'message' => 'Body is not a JSON object.'];
            }

            $summary = $this->processChanges($payload);

            $response->setStatus(200);

            return (object) [
                'ok'      => true,
                'queued'  => $summary['queued'],
                'skipped' => $summary['skipped'],
            ];
        } catch (Throwable $e) {
            $this->log->error('MetaLeadAdsWebhook: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            // Still return 200 — we don't want Meta retrying after our internal
            // bug. The webhook is best-effort; observability is via logs.
            $response->setStatus(200);

            return (object) ['ok' => false, 'message' => 'Internal error (logged).'];
        }
    }

    /**
     * Extract :providerId from route params. Returns null when the legacy
     * bare path was used.
     */
    private function extractProviderId(Request $request): ?string
    {
        $value = $request->getRouteParam('providerId');

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Walk payload.entry[].changes[] and enqueue jobs for `field=leadgen` items.
     *
     * Meta payload shape (Page object, leadgen field):
     *   {
     *     "object": "page",
     *     "entry": [{
     *       "id": "{pageId}",
     *       "time": 1234567890,
     *       "changes": [{
     *         "field": "leadgen",
     *         "value": {
     *           "leadgen_id": "...",
     *           "page_id":    "...",
     *           "form_id":    "...",
     *           "ad_id":      "...",
     *           "adgroup_id": "...",
     *           "created_time": 1234567890
     *         }
     *       }]
     *     }]
     *   }
     *
     * @return array{queued: int, skipped: int}
     */
    private function processChanges(stdClass $payload): array
    {
        $queued = 0;
        $skipped = 0;

        $entries = $payload->entry ?? [];
        if (!is_array($entries)) {
            return ['queued' => 0, 'skipped' => 0];
        }

        foreach ($entries as $entry) {
            if (!$entry instanceof stdClass) {
                continue;
            }

            $changes = $entry->changes ?? [];
            if (!is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                if (!$change instanceof stdClass) {
                    continue;
                }

                if (($change->field ?? null) !== 'leadgen') {
                    $skipped++;
                    continue;
                }

                $value = $change->value ?? null;
                if (!$value instanceof stdClass) {
                    $skipped++;
                    continue;
                }

                $leadgenId   = isset($value->leadgen_id)   ? (string) $value->leadgen_id   : '';
                $metaPageId  = isset($value->page_id)      ? (string) $value->page_id      : '';
                $formId      = isset($value->form_id)      ? (string) $value->form_id      : '';
                $adId        = isset($value->ad_id)        ? (string) $value->ad_id        : null;
                $createdTime = isset($value->created_time) ? (int)    $value->created_time : null;

                if ($leadgenId === '' || $formId === '') {
                    $skipped++;
                    continue;
                }

                $event = $this->upsertEvent($leadgenId, $metaPageId, $formId, $adId, $createdTime, (array) $value);

                if (!$event) {
                    $skipped++;
                    continue;
                }

                $this->jobSchedulerFactory
                    ->create()
                    ->setClassName(IngestLeadgen::class)
                    ->setData(['eventId' => $event->getId()])
                    ->setGroup('meta-leadgen-' . $leadgenId)
                    ->schedule();

                $queued++;
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Upsert MetaLeadgenEvent — idempotent on leadgenId.
     *
     * Field/link semantics on MetaLeadgenEvent:
     *   - `formId`      (scalar)  — Meta numeric form id (from webhook).
     *   - `metaPageId`  (scalar)  — Meta numeric page id (from webhook).
     *   - `leadFormId`  (foreign) — CRM id of linked MetaLeadForm (resolved
     *                                from `formId` if the form is known).
     *   - `pageId`      (foreign) — CRM id of linked MetaFacebookPage
     *                                (resolved from `metaPageId` if the page
     *                                is known). NOT the Meta numeric.
     *
     * Tenancy: looks up MetaLeadForm by formId (string) and propagates
     * leadFormId (link), teamsIds, and tenantId onto the event before save.
     * The CascadeTeamsFromLeadForm / CascadeTenantFromLeadForm hooks
     * (order=1) would normally do this — but they early-return on `silent`,
     * and we save with `silent => true` to suppress Stream notifications
     * for system-internal webhook rows. So we do the work here.
     *
     * If the form is not yet known (admin hasn't synced it), the event is
     * still persisted as an "orphan" without teams/tenant. The IngestLeadgen
     * job will pick it up, attempt to sync the form on-demand from the
     * linked Page, and only mark it Skipped if the form is genuinely
     * missing from Meta as well.
     *
     * @param array<string, mixed> $rawValue
     */
    private function upsertEvent(
        string $leadgenId,
        string $metaPageId,
        string $formId,
        ?string $adId,
        ?int $createdTime,
        array $rawValue,
    ): ?MetaLeadgenEvent {
        $existing = $this->entityManager
            ->getRDBRepository(MetaLeadgenEvent::ENTITY_TYPE)
            ->where(['leadgenId' => $leadgenId, 'deleted' => false])
            ->findOne();

        if ($existing instanceof MetaLeadgenEvent) {
            // Already known — let the job run (it'll early-exit if already processed).
            return $existing;
        }

        try {
            /** @var MetaLeadgenEvent $event */
            $event = $this->entityManager->getNewEntity(MetaLeadgenEvent::ENTITY_TYPE);

            $event->set('name',       "Lead {$leadgenId}");
            $event->set('leadgenId',  $leadgenId);
            $event->set('formId',     $formId);
            $event->set('metaPageId', $metaPageId);
            $event->set('adId',       $adId);
            $event->set('metaCreatedTime', $createdTime ? date('Y-m-d H:i:s', $createdTime) : null);
            $event->set('status',     MetaLeadgenEvent::STATUS_RECEIVED);
            $event->set('rawPayload', $rawValue);

            // Resolve `page` link (CRM id) from Meta numeric page id, if the
            // page is already synced locally. Safe to leave null otherwise —
            // the ingester will attempt a sync-on-demand before rejecting.
            $this->resolvePageLink($event, $metaPageId);

            // Tenant propagation: lookup the form to derive leadFormId +
            // teamsIds + tenantId. Without this the event is created
            // orphaned and effectively visible to all users via team ACL
            // (a real PII leak in shared-DB multi-tenant deployments).
            $this->propagateTenancyFromForm($event, $formId);

            $this->entityManager->saveEntity($event, ['skipHooks' => true, 'silent' => true]);

            return $event;
        } catch (Throwable $e) {
            $this->log->error('MetaLeadAds: failed to persist MetaLeadgenEvent: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Set the `page` link (foreign-id `pageId` = CRM id) from a Meta numeric
     * page id by looking up MetaFacebookPage. No-op if the page is not yet
     * synced locally — the ingester will attempt on-demand sync.
     */
    private function resolvePageLink(MetaLeadgenEvent $event, string $metaPageId): void
    {
        if ($metaPageId === '') {
            return;
        }

        $page = $this->entityManager
            ->getRDBRepository('MetaFacebookPage')
            ->where(['pageId' => $metaPageId, 'deleted' => false])
            ->findOne();

        if ($page) {
            $event->set('pageId', $page->getId());
        }
    }

    /**
     * Set leadFormId + teamsIds + tenantId on the event from the matching
     * MetaLeadForm row (looked up by string formId).
     *
     * If no form is known yet, the event is left without teams/tenant —
     * a tenant-isolation breach risk. We log a warning so the orphan is
     * surfaced in operator monitoring.
     */
    private function propagateTenancyFromForm(MetaLeadgenEvent $event, string $formId): void
    {
        $form = $this->entityManager
            ->getRDBRepository('MetaLeadForm')
            ->where(['formId' => $formId, 'deleted' => false])
            ->findOne();

        if (!$form) {
            $this->log->warning(
                "MetaLeadAds: leadgen webhook for unknown formId={$formId}; " .
                "event will be created without teams/tenant. " .
                "Run Sync Forms on the parent MetaFacebookPage to enable proper tenancy."
            );

            return;
        }

        $event->set('leadFormId', $form->getId());

        $teamIds = [];

        try {
            $teamIds = $form->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $teamIds = [];
        }

        if (!empty($teamIds)) {
            $event->set('teamsIds', array_values(array_unique($teamIds)));
        }

        $tenantId = $form->get('tenantId');

        if ($tenantId) {
            $event->set('tenantId', $tenantId);
        }

        if (empty($teamIds)) {
            $this->log->warning(
                "MetaLeadAds: MetaLeadForm {$form->getId()} has no teams; " .
                "leadgen event will inherit the missing-teams state. " .
                "Assign teams on the parent MetaFacebookPage."
            );
        }
    }

    /**
     * Resolve a specific meta-leadads OAuthProvider by id.
     * Returns null if not found, inactive, deleted, or not meta-leadads.
     */
    private function resolveProviderById(string $providerId): ?OAuthProvider
    {
        $provider = $this->entityManager
            ->getRDBRepository(OAuthProvider::ENTITY_TYPE)
            ->where([
                'id'       => $providerId,
                'provider' => self::PROVIDER_DISCRIMINATOR,
                'isActive' => true,
                'deleted'  => false,
            ])
            ->findOne();

        return $provider instanceof OAuthProvider ? $provider : null;
    }

    /**
     * Resolve every active meta-leadads provider in the DB (legacy bare-path
     * fallback only — try-all-secrets pattern).
     *
     * @return OAuthProvider[]
     */
    private function resolveAllActiveProviders(): array
    {
        /** @var OAuthProvider[] $providers */
        $providers = $this->entityManager
            ->getRDBRepository(OAuthProvider::ENTITY_TYPE)
            ->where([
                'provider' => self::PROVIDER_DISCRIMINATOR,
                'isActive' => true,
                'deleted'  => false,
            ])
            ->find();

        return is_array($providers) ? $providers : iterator_to_array($providers);
    }

    /**
     * BYOA signature check — exactly one provider candidate.
     */
    private function verifySignatureForProvider(
        string $providerId,
        string $rawBody,
        Request $request,
    ): ?OAuthProvider {
        $provider = $this->resolveProviderById($providerId);

        if (!$provider) {
            $this->log->warning('MetaLeadAds: webhook delivery for unknown providerId.', [
                'providerId' => $providerId,
            ]);

            return null;
        }

        $error = $this->verifySignature($provider, $rawBody, $request);

        if ($error !== null) {
            $this->log->warning("MetaLeadAds: signature verification failed: {$error}", [
                'providerId' => $providerId,
            ]);

            return null;
        }

        return $provider;
    }

    /**
     * Legacy bare-path signature check — iterate every active provider and
     * accept the first whose clientSecret validates the HMAC. This is the
     * try-all-secrets pattern used by the WhatsApp/Instagram modules.
     */
    private function verifySignatureTryAll(string $rawBody, Request $request): ?OAuthProvider
    {
        $providers = $this->resolveAllActiveProviders();

        if (empty($providers)) {
            $this->log->warning('MetaLeadAds: no active meta-leadads provider configured.');

            return null;
        }

        foreach ($providers as $provider) {
            if ($this->verifySignature($provider, $rawBody, $request) === null) {
                return $provider;
            }
        }

        $this->log->warning('MetaLeadAds: no provider secret matched X-Hub-Signature-256.', [
            'providersTried' => count($providers),
        ]);

        return null;
    }

    private function verifySignature(OAuthProvider $provider, string $rawBody, Request $request): ?string
    {
        $encryptedSecret = (string) ($provider->get('clientSecret') ?? '');

        if ($encryptedSecret === '') {
            return 'Provider clientSecret not set.';
        }

        try {
            $appSecret = $this->crypt->decrypt($encryptedSecret);
        } catch (Throwable $e) {
            return 'Provider clientSecret unreadable.';
        }

        $provided = $request->getHeader('X-Hub-Signature-256');

        if (!is_string($provided) || $provided === '') {
            return 'Missing X-Hub-Signature-256 header.';
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

        if (!hash_equals($expected, $provided)) {
            return 'Invalid X-Hub-Signature-256.';
        }

        return null;
    }

    private function decryptVerifyToken(OAuthProvider $provider): ?string
    {
        $encrypted = (string) ($provider->get('webhookVerifyToken') ?? '');

        if ($encrypted === '') {
            return null;
        }

        try {
            return $this->crypt->decrypt($encrypted);
        } catch (Throwable $e) {
            $this->log->error('MetaLeadAds: failed to decrypt webhookVerifyToken: ' . $e->getMessage());

            return null;
        }
    }
}
