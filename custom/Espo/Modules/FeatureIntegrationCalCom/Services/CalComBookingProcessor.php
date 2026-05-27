<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationCalCom\Services;

use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureIntegrationCalCom\Entities\CalComIntegration;
use Espo\ORM\EntityManager;
use stdClass;
use Throwable;

/**
 * Orchestrates a cal.com webhook end-to-end.
 *
 * Steps:
 *   1. Resolve CalComIntegration by its entity id (the `:id` route segment).
 *   2. Validate HMAC signature (if signingSecret is configured).
 *   3. Parse payload via CalComPayloadParser.
 *   4. Match/create Contact via CalComContactMatcher (captures Meta/Google
 *      tracking identifiers from the booking onto the Contact).
 *   5. Update integration counters / lastWebhookStatus.
 */
class CalComBookingProcessor
{
    public function __construct(
        private EntityManager $entityManager,
        private CalComPayloadParser $parser,
        private CalComContactMatcher $matcher,
        private Crypt $crypt,
        private Log $log,
    ) {}

    /**
     * @param array<string, string> $headers  Lower-cased header map.
     */
    public function process(
        string $integrationId,
        stdClass $rawPayload,
        string $rawBody,
        array $headers,
    ): ProcessResult {
        $integration = $this->resolveIntegration($integrationId);

        if (!$integration) {
            return ProcessResult::notFound('Unknown CalComIntegration id.');
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

        return $this->finish($integration, ProcessResult::accepted([
            'contactId'    => $contact->getId(),
            'triggerEvent' => $booking->triggerEvent,
            'bookingUid'   => $booking->bookingUid,
        ]));
    }

    private function resolveIntegration(string $integrationId): ?CalComIntegration
    {
        if ($integrationId === '') {
            return null;
        }

        $entity = $this->entityManager
            ->getRDBRepository(CalComIntegration::ENTITY_TYPE)
            ->where([
                'id'      => $integrationId,
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
