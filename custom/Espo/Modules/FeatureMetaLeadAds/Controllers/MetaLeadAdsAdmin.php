<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadgenEvent;
use Espo\Modules\FeatureMetaLeadAds\Jobs\IngestLeadgen;
use Espo\Modules\FeatureMetaLeadAds\Services\FormSyncService;
use Espo\Modules\FeatureMetaLeadAds\Services\PageSyncService;
use Espo\ORM\EntityManager;
use stdClass;
use Throwable;

/**
 * Authenticated admin actions for Meta Lead Ads setup.
 *
 * Routes (auth required, defined in Resources/routes.json):
 *   POST /MetaLeadAds/syncPages    { oAuthAccountId }
 *   POST /MetaLeadAds/syncForms    { pageId }       // internal MetaFacebookPage id
 *   POST /MetaLeadAds/retryIngest  { eventId }      // re-enqueue failed leadgen
 *
 * Usage flow:
 *   1. Admin connects OAuthAccount for provider "meta-leadads" (regular Espo OAuth UI).
 *   2. Admin clicks "Sync Meta Pages" on the OAuthAccount detail.
 *      -> MetaFacebookPage rows created for each managed page, tokens encrypted,
 *         app subscribed to leadgen on each page.
 *   3. For each MetaFacebookPage, admin clicks "Sync Forms".
 *      -> MetaLeadForm rows created (status: inactive — admin needs to assign
 *         a Funnel before they accept leads).
 *   4. Admin opens each MetaLeadForm, assigns Funnel + opportunityStage +
 *      assignedUser + fieldMapping, sets isActive=true.
 *   5. Leads start flowing automatically through /MetaLeadAds/webhook (POST).
 *   6. Failed events can be re-tried from the MetaLeadgenEvent detail view.
 */
class MetaLeadAdsAdmin
{
    public function __construct(
        private InjectableFactory $injectableFactory,
        private EntityManager $entityManager,
        private JobSchedulerFactory $jobSchedulerFactory,
        private Log $log,
    ) {}

    public function postActionSyncPages(Request $request, Response $response): stdClass
    {
        $body = $request->getParsedBody();
        $oAuthAccountId = $body instanceof stdClass ? (string) ($body->oAuthAccountId ?? '') : '';

        if ($oAuthAccountId === '') {
            throw new BadRequest('oAuthAccountId is required.');
        }

        try {
            $result = $this->injectableFactory
                ->create(PageSyncService::class)
                ->syncForOAuthAccount($oAuthAccountId);

            $response->setStatus(200);
            $response->setHeader('Content-Type', 'application/json');

            return (object) $result;
        } catch (Throwable $e) {
            $this->log->error('MetaLeadAdsAdmin.syncPages: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $response->setStatus(500);
            $response->setHeader('Content-Type', 'application/json');

            return (object) [
                'ok'    => false,
                'error' => 'Internal error: ' . $e->getMessage(),
            ];
        }
    }

    public function postActionSyncForms(Request $request, Response $response): stdClass
    {
        $body = $request->getParsedBody();
        $pageId = $body instanceof stdClass ? (string) ($body->pageId ?? '') : '';

        if ($pageId === '') {
            throw new BadRequest('pageId (internal MetaFacebookPage id) is required.');
        }

        try {
            $result = $this->injectableFactory
                ->create(FormSyncService::class)
                ->syncForPage($pageId);

            $response->setStatus(200);
            $response->setHeader('Content-Type', 'application/json');

            return (object) $result;
        } catch (Throwable $e) {
            $this->log->error('MetaLeadAdsAdmin.syncForms: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $response->setStatus(500);
            $response->setHeader('Content-Type', 'application/json');

            return (object) [
                'ok'    => false,
                'error' => 'Internal error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Re-enqueue an IngestLeadgen job for a previously-failed event.
     *
     * Clears the failure state on the event row and schedules a fresh job.
     * The ingester is idempotent — early-exits if it sees status=Processed.
     */
    public function postActionRetryIngest(Request $request, Response $response): stdClass
    {
        $body = $request->getParsedBody();
        $eventId = $body instanceof stdClass ? (string) ($body->eventId ?? '') : '';

        if ($eventId === '') {
            throw new BadRequest('eventId is required.');
        }

        $event = $this->entityManager->getEntityById(MetaLeadgenEvent::ENTITY_TYPE, $eventId);

        if (!$event instanceof MetaLeadgenEvent) {
            throw new NotFound("MetaLeadgenEvent {$eventId} not found.");
        }

        $event->set('status',       MetaLeadgenEvent::STATUS_RECEIVED);
        $event->set('errorMessage', null);
        $event->set('processedAt',  null);

        try {
            $this->entityManager->saveEntity($event, ['skipHooks' => true, 'silent' => true]);
        } catch (Throwable $e) {
            $this->log->error('MetaLeadAdsAdmin.retryIngest: failed to reset event: ' . $e->getMessage());

            $response->setStatus(500);
            $response->setHeader('Content-Type', 'application/json');

            return (object) [
                'ok'    => false,
                'error' => 'Could not reset event status: ' . $e->getMessage(),
            ];
        }

        try {
            $this->jobSchedulerFactory
                ->create()
                ->setClassName(IngestLeadgen::class)
                ->setData(['eventId' => $event->getId()])
                ->setGroup('meta-leadgen-' . ((string) $event->get('leadgenId') ?: $event->getId()))
                ->schedule();
        } catch (Throwable $e) {
            $this->log->error('MetaLeadAdsAdmin.retryIngest: failed to schedule job: ' . $e->getMessage());

            $response->setStatus(500);
            $response->setHeader('Content-Type', 'application/json');

            return (object) [
                'ok'    => false,
                'error' => 'Could not schedule retry: ' . $e->getMessage(),
            ];
        }

        $response->setStatus(200);
        $response->setHeader('Content-Type', 'application/json');

        return (object) [
            'ok'      => true,
            'eventId' => $event->getId(),
        ];
    }
}
