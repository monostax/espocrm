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
 *   GET  /MetaLeadAds/webhook   — Meta's subscription verification handshake.
 *   POST /MetaLeadAds/webhook   — Live leadgen delivery.
 *
 * URL registered in the Meta App dashboard (per tenant):
 *   https://{tenant}.{host}/api/v1/MetaLeadAds/webhook
 *
 * SECURITY — POST verification:
 *   X-Hub-Signature-256 = "sha256=" + hex(HMAC-SHA256(rawBody, appSecret))
 *   appSecret = OAuthProvider("meta-leadads").clientSecret  (decrypted)
 *
 * SECURITY — GET handshake:
 *   ?hub.mode=subscribe&hub.verify_token={token}&hub.challenge=...
 *   Must echo back the challenge AS PLAIN TEXT if token matches
 *   OAuthProvider("meta-leadads").webhookVerifyToken (decrypted).
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
 *   200 — accepted (with summary)
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

        $provider = $this->resolveProvider();
        if (!$provider) {
            $this->log->error('MetaLeadAds: webhook verify failed — no active provider found.');
            $response->setStatus(403);

            return;
        }

        $expectedToken = $this->decryptVerifyToken($provider);

        if ($expectedToken === null) {
            $this->log->error('MetaLeadAds: webhook verify failed — no webhookVerifyToken set on provider.');
            $response->setStatus(403);

            return;
        }

        if (!hash_equals($expectedToken, $token)) {
            $this->log->warning('MetaLeadAds: webhook verify failed — token mismatch.');
            $response->setStatus(403);

            return;
        }

        $response->setStatus(200);
        $response->setHeader('Content-Type', 'text/plain');
        $response->writeBody($challenge);
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

            $provider = $this->resolveProvider();

            if (!$provider) {
                $response->setStatus(401);

                return (object) ['ok' => false, 'message' => 'No active meta-leadads provider configured.'];
            }

            $sigError = $this->verifySignature($provider, $rawBody, $request);
            if ($sigError !== null) {
                $this->log->warning('MetaLeadAds: signature verification failed: ' . $sigError);
                $response->setStatus(401);

                return (object) ['ok' => false, 'message' => $sigError];
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
                $pageId      = isset($value->page_id)      ? (string) $value->page_id      : '';
                $formId      = isset($value->form_id)      ? (string) $value->form_id      : '';
                $adId        = isset($value->ad_id)        ? (string) $value->ad_id        : null;
                $createdTime = isset($value->created_time) ? (int)    $value->created_time : null;

                if ($leadgenId === '' || $formId === '') {
                    $skipped++;
                    continue;
                }

                $event = $this->upsertEvent($leadgenId, $pageId, $formId, $adId, $createdTime, (array) $value);

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
     * @param array<string, mixed> $rawValue
     */
    private function upsertEvent(
        string $leadgenId,
        string $pageId,
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

            $event->set('name',      "Lead {$leadgenId}");
            $event->set('leadgenId', $leadgenId);
            $event->set('formId',    $formId);
            $event->set('pageId',    $pageId);
            $event->set('adId',      $adId);
            $event->set('metaCreatedTime', $createdTime ? date('Y-m-d H:i:s', $createdTime) : null);
            $event->set('status',    MetaLeadgenEvent::STATUS_RECEIVED);
            $event->set('rawPayload', $rawValue);

            $this->entityManager->saveEntity($event, ['skipHooks' => true, 'silent' => true]);

            return $event;
        } catch (Throwable $e) {
            $this->log->error('MetaLeadAds: failed to persist MetaLeadgenEvent: ' . $e->getMessage());

            return null;
        }
    }

    private function resolveProvider(): ?OAuthProvider
    {
        $provider = $this->entityManager
            ->getRDBRepository(OAuthProvider::ENTITY_TYPE)
            ->where([
                'provider' => self::PROVIDER_DISCRIMINATOR,
                'isActive' => true,
                'deleted'  => false,
            ])
            ->findOne();

        return $provider instanceof OAuthProvider ? $provider : null;
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
