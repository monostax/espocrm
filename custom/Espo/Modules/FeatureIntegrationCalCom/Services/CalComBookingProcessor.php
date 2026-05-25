<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationCalCom\Services;

use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Contact;
use Espo\Modules\FeatureIntegrationCalCom\Entities\CalComIntegration;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\Modules\FeatureMetaConversionsApi\Jobs\SendCapiEvent;
use Espo\ORM\EntityManager;
use stdClass;
use Throwable;

/**
 * Orchestrates a cal.com webhook end-to-end.
 *
 * Steps:
 *   1. Resolve CalComIntegration from apiKey.
 *   2. Validate HMAC signature (if signingSecret is configured).
 *   3. Parse payload via CalComPayloadParser.
 *   4. Match/create Contact via CalComContactMatcher.
 *   5. If integration has metaCapiDataset linked AND metaCapiEventMapping has a value
 *      for the triggerEvent, enqueue Meta CAPI event via SendCapiEvent job.
 *   6. Update integration counters / lastWebhookStatus.
 *
 * If integration has NO metaCapiDataset, the webhook is still ACCEPTED — Contact
 * matching/creation runs. Useful when cal.com is needed for CRM hygiene
 * even without ad attribution.
 */
class CalComBookingProcessor
{
    public function __construct(
        private EntityManager $entityManager,
        private CalComPayloadParser $parser,
        private CalComContactMatcher $matcher,
        private JobSchedulerFactory $jobSchedulerFactory,
        private Crypt $crypt,
        private Log $log,
    ) {}

    /**
     * @param array<string, string> $headers  Lower-cased header map.
     */
    public function process(
        string $apiKey,
        stdClass $rawPayload,
        string $rawBody,
        array $headers,
    ): ProcessResult {
        $integration = $this->resolveIntegration($apiKey);

        if (!$integration) {
            return ProcessResult::notFound('Unknown cal.com apiKey.');
        }

        if (!$integration->get('isActive')) {
            return $this->finish($integration, ProcessResult::skipped('Integration is inactive.'));
        }

        $sigError = $this->verifySignature($integration, $rawBody, $headers);
        if ($sigError !== null) {
            return $this->finish($integration, ProcessResult::unauthorized($sigError));
        }

        $booking = $this->parser->parse($rawPayload);

        if (!$booking) {
            return $this->finish(
                $integration,
                ProcessResult::badRequest('Malformed cal.com payload (missing triggerEvent/payload/attendees).'),
            );
        }

        $createIfMissing = (bool) ($integration->get('createContactIfMissing') ?? true);
        $contact = $this->matcher->matchOrCreate($booking, $createIfMissing, $integration);

        if (!$contact) {
            return $this->finish(
                $integration,
                ProcessResult::skipped('No Contact matched and creation disabled.'),
            );
        }

        // CAPI forwarding is optional. If not configured -> still ACCEPT the webhook.
        $datasetId = $integration->get('metaCapiDatasetId');
        $capiEventName = $datasetId
            ? $this->resolveCapiEventName($integration, $booking->triggerEvent)
            : null;

        $details = [
            'contactId'    => $contact->getId(),
            'triggerEvent' => $booking->triggerEvent,
            'bookingUid'   => $booking->bookingUid,
        ];

        if ($datasetId && $capiEventName !== null) {
            $this->enqueueCapiEvent($datasetId, $contact, $capiEventName, $booking);
            $details['capiEventName'] = $capiEventName;
            $details['capiDispatched'] = true;
        } else {
            $details['capiDispatched'] = false;
            $details['capiSkipReason'] = $datasetId
                ? sprintf('No CAPI mapping for triggerEvent=%s.', $booking->triggerEvent)
                : 'No metaCapiDataset linked to this integration.';
        }

        return $this->finish($integration, ProcessResult::accepted($details));
    }

    private function resolveIntegration(string $apiKey): ?CalComIntegration
    {
        if ($apiKey === '') {
            return null;
        }

        $entity = $this->entityManager
            ->getRDBRepository(CalComIntegration::ENTITY_TYPE)
            ->where([
                'apiKey'  => $apiKey,
                'deleted' => false,
            ])
            ->findOne();

        return $entity instanceof CalComIntegration ? $entity : null;
    }

    /**
     * @param array<string, string> $headers
     * @return string|null  Error message, or null if OK / not required.
     */
    private function verifySignature(CalComIntegration $integration, string $rawBody, array $headers): ?string
    {
        $encryptedSecret = (string) ($integration->get('signingSecret') ?? '');

        if ($encryptedSecret === '') {
            return null;
        }

        try {
            $secret = $this->crypt->decrypt($encryptedSecret);
        } catch (Throwable $e) {
            $this->log->error('CalCom: failed to decrypt signingSecret: ' . $e->getMessage());

            return 'Server signing secret unreadable.';
        }

        $providedSig = $headers['x-cal-signature-256'] ?? null;

        if (!$providedSig) {
            return 'Missing X-Cal-Signature-256 header.';
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        if (!hash_equals($expected, $providedSig)) {
            return 'Invalid X-Cal-Signature-256.';
        }

        return null;
    }

    private function resolveCapiEventName(CalComIntegration $integration, string $triggerEvent): ?string
    {
        $mapping = $integration->get('metaCapiEventMapping');

        if (!is_object($mapping) && !is_array($mapping)) {
            return null;
        }

        if (is_object($mapping)) {
            $mapping = (array) $mapping;
        }

        $eventName = $mapping[$triggerEvent] ?? null;

        if (!is_string($eventName)) {
            return null;
        }

        $eventName = trim($eventName);

        return $eventName === '' ? null : $eventName;
    }

    private function enqueueCapiEvent(
        string $datasetId,
        Contact $contact,
        string $eventName,
        ParsedCalComBooking $booking,
    ): void {
        $customData = $this->buildCapiCustomData($booking);

        // event_id derived from bookingUid + eventName so we can dedupe with
        // a browser-side Pixel call on the booking confirmation page.
        $eventId = $booking->bookingUid
            ? hash('sha256', sprintf('calcom:%s:%s', $booking->bookingUid, $eventName))
            : null;

        try {
            $this->jobSchedulerFactory
                ->create()
                ->setClassName(SendCapiEvent::class)
                ->setData([
                    'entityType' => Contact::ENTITY_TYPE,
                    'entityId'   => $contact->getId(),
                    'eventName'  => $eventName,
                    'datasetId'  => $datasetId,
                    'context'    => [
                        'eventId'    => $eventId,
                        'customData' => $customData,
                    ],
                ])
                ->setGroup('calcom-' . ($booking->bookingUid ?? $contact->getId()))
                ->schedule();
        } catch (Throwable $e) {
            $this->log->error('CalCom: failed to enqueue SendCapiEvent: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCapiCustomData(ParsedCalComBooking $booking): array
    {
        $data = [
            'source'        => 'cal.com',
            'trigger_event' => $booking->triggerEvent,
        ];

        if ($booking->bookingUid)    { $data['booking_uid']      = $booking->bookingUid; }
        if ($booking->eventTypeSlug) { $data['content_name']     = $booking->eventTypeSlug; }
        if ($booking->eventTypeId)   { $data['content_ids']      = [$booking->eventTypeId]; }
        if ($booking->title)         { $data['content_category'] = $booking->title; }
        if ($booking->startTime)     {
            $data['delivery_category'] = 'in_store';
            $data['scheduled_for']     = $booking->startTime;
        }

        return $data;
    }

    private function finish(CalComIntegration $integration, ProcessResult $result): ProcessResult
    {
        $statusMap = [
            ProcessResult::ACCEPTED     => CalComIntegration::STATUS_ACCEPTED,
            ProcessResult::SKIPPED      => CalComIntegration::STATUS_SKIPPED,
            ProcessResult::BAD_REQUEST  => CalComIntegration::STATUS_BAD_REQUEST,
            ProcessResult::UNAUTHORIZED => CalComIntegration::STATUS_UNAUTHORIZED,
            ProcessResult::NOT_FOUND    => null,
        ];

        $newStatus = $statusMap[$result->status] ?? null;

        if ($newStatus === null) {
            return $result;
        }

        $integration->set('lastWebhookReceivedAt', date('Y-m-d H:i:s'));
        $integration->set('lastWebhookStatus', $newStatus);
        $integration->set('lastWebhookError', $result->status === ProcessResult::ACCEPTED ? null : $result->message);
        $integration->set('totalWebhooksReceived', (int) ($integration->get('totalWebhooksReceived') ?? 0) + 1);

        try {
            $this->entityManager->saveEntity($integration, ['skipHooks' => true, 'silent' => true]);
        } catch (Throwable $e) {
            $this->log->warning('CalCom: failed to update integration counters: ' . $e->getMessage());
        }

        return $result;
    }
}
