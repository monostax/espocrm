<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionMapping;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionUpload;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsDestination;
use Espo\Modules\FeatureGoogleAdsConversions\Jobs\SendGoogleAdsConversion;
use Espo\Modules\FeatureGoogleAdsConversions\Rebuild\SeedOAuthProviderGoogleDataManager;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\EntityManager;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Sends immutable upload rows and owns their retry state transitions.
 */
class GoogleAdsDispatcher
{
    public const MAX_ATTEMPTS = 7;

    /** @var list<int> Delays after failed attempts 1 through 6. */
    private const RETRY_DELAYS = [60, 300, 900, 3600, 21600, 86400];

    public function __construct(
        private EntityManager $entityManager,
        private TenantResolver $tenantResolver,
        private Crypt $crypt,
        private DataManagerClient $client,
        private JobSchedulerFactory $jobSchedulerFactory,
        private Log $log,
    ) {}

    public function dispatch(string $uploadId): void
    {
        $upload = $this->entityManager->getEntityById(
            GoogleAdsConversionUpload::ENTITY_TYPE,
            $uploadId,
        );

        if (!$upload instanceof GoogleAdsConversionUpload) {
            $this->log->warning('Google Ads dispatch skipped: upload record not found.');
            return;
        }

        if (in_array($upload->get('status'), GoogleAdsConversionUpload::TERMINAL_STATUSES, true)) {
            return;
        }

        if (!$this->isDue($upload)) {
            return;
        }

        if ((int) $upload->get('attemptCount') >= self::MAX_ATTEMPTS) {
            $this->failPermanent($upload, 'MAX_ATTEMPTS', 'Maximum upload attempts reached.');
            return;
        }

        try {
            [$destination, $mapping, $oAuthAccountId] = $this->runtimeConfiguration($upload);
        } catch (Throwable) {
            $this->failPermanent(
                $upload,
                'CONFIGURATION_MISMATCH',
                'Upload configuration failed tenant, provider, or relationship validation.',
            );
            return;
        }

        if (!$destination->get('isActive')) {
            $this->skip($upload, 'DESTINATION_INACTIVE', 'Google Ads destination is inactive.');
            return;
        }

        if (!$mapping->get('isActive')) {
            $this->skip($upload, 'MAPPING_INACTIVE', 'Google Ads conversion mapping is inactive.');
            return;
        }

        try {
            $request = $this->decryptRequest($upload, $destination);
        } catch (Throwable) {
            $this->failPermanent(
                $upload,
                'IMMUTABLE_PAYLOAD_INVALID',
                'The encrypted upload payload is missing, invalid, or inconsistent.',
            );
            return;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $attempt = (int) $upload->get('attemptCount') + 1;
        $upload->set([
            'status' => GoogleAdsConversionUpload::STATUS_PROCESSING,
            'attemptCount' => $attempt,
            'lastAttemptAt' => $now->format('Y-m-d H:i:s'),
            'nextAttemptAt' => null,
        ]);
        $this->saveUpload($upload);

        try {
            $result = $this->client->ingest($oAuthAccountId, $request);
        } catch (Throwable) {
            $result = new DataManagerResult(
                success: false,
                retryable: true,
                httpStatus: 0,
                errorCode: 'CLIENT_FAILURE',
                errorMessage: 'Data Manager client failed before receiving a response.',
            );
        }
        $upload->set([
            'httpStatus' => $result->httpStatus ?: null,
            'requestId' => $result->requestId,
            'fieldWarnings' => $result->fieldWarnings,
            'errorCode' => $result->errorCode,
            'errorMessage' => $result->errorMessage,
        ]);

        if ($result->success) {
            $upload->set([
                'status' => $upload->get('validateOnly')
                    ? GoogleAdsConversionUpload::STATUS_VALIDATED
                    : GoogleAdsConversionUpload::STATUS_SENT,
                'sentAt' => $now->format('Y-m-d H:i:s'),
                'nextAttemptAt' => null,
                'errorCode' => null,
                'errorMessage' => null,
            ]);
            $this->saveUpload($upload);
            $this->updateDestination($destination, true, null);
            return;
        }

        $errorMessage = $result->errorMessage ?? 'Data Manager rejected the request.';

        if (!$result->retryable || $attempt >= self::MAX_ATTEMPTS) {
            $upload->set([
                'status' => GoogleAdsConversionUpload::STATUS_FAILED_PERMANENT,
                'nextAttemptAt' => null,
            ]);
            $this->saveUpload($upload);
            $this->updateDestination($destination, false, $errorMessage);
            return;
        }

        $delay = self::RETRY_DELAYS[$attempt - 1];
        $nextAttemptAt = $now->add(new DateInterval('PT' . $delay . 'S'));
        $upload->set([
            'status' => GoogleAdsConversionUpload::STATUS_RETRY_SCHEDULED,
            'nextAttemptAt' => $nextAttemptAt->format('Y-m-d H:i:s'),
        ]);
        $this->saveUpload($upload);
        $this->updateDestination($destination, false, $errorMessage);
    }

    public function schedule(string $uploadId, int $delaySeconds = 0): void
    {
        $scheduler = $this->jobSchedulerFactory
            ->create()
            ->setClassName(SendGoogleAdsConversion::class)
            ->setData(['uploadId' => $uploadId])
            ->setGroup('google-ads-upload-' . $uploadId);

        if ($delaySeconds > 0) {
            $scheduler->setDelay(new DateInterval('PT' . $delaySeconds . 'S'));
        }

        $scheduler->schedule();
    }

    /**
     * @return array{GoogleAdsDestination, GoogleAdsConversionMapping, string}
     */
    private function runtimeConfiguration(GoogleAdsConversionUpload $upload): array
    {
        $tenantId = trim((string) ($upload->get('tenantId') ?? ''));
        $destinationId = trim((string) ($upload->get('destinationId') ?? ''));
        $mappingId = trim((string) ($upload->get('mappingId') ?? ''));
        $destination = $destinationId !== ''
            ? $this->entityManager->getEntityById(GoogleAdsDestination::ENTITY_TYPE, $destinationId)
            : null;
        $mapping = $mappingId !== ''
            ? $this->entityManager->getEntityById(GoogleAdsConversionMapping::ENTITY_TYPE, $mappingId)
            : null;

        if (!$destination instanceof GoogleAdsDestination || !$mapping instanceof GoogleAdsConversionMapping) {
            throw new RuntimeException('Missing related configuration.');
        }

        if (
            $tenantId === '' ||
            $tenantId !== (string) $destination->get('tenantId') ||
            $tenantId !== (string) $mapping->get('tenantId') ||
            (string) $mapping->get('destinationId') !== $destination->getId()
        ) {
            throw new RuntimeException('Tenant or relationship mismatch.');
        }

        $opportunityId = trim((string) ($upload->get('opportunityId') ?? ''));
        $opportunity = $opportunityId !== ''
            ? $this->entityManager->getEntityById('Opportunity', $opportunityId)
            : null;

        if (!$opportunity || (string) $opportunity->get('tenantId') !== $tenantId) {
            throw new RuntimeException('Opportunity tenant mismatch.');
        }

        $contactId = trim((string) ($upload->get('contactId') ?? ''));

        if ($contactId !== '') {
            $contact = $this->entityManager->getEntityById('Contact', $contactId);

            if (!$contact || (string) $contact->get('tenantId') !== $tenantId) {
                throw new RuntimeException('Contact tenant mismatch.');
            }
        }

        $oAuthAccountId = trim((string) ($destination->get('oAuthAccountId') ?? ''));
        $account = $oAuthAccountId !== ''
            ? $this->entityManager->getEntityById('OAuthAccount', $oAuthAccountId)
            : null;

        if (!$account instanceof CoreEntity) {
            throw new RuntimeException('OAuth account missing.');
        }

        $providerId = trim((string) ($account->get('providerId') ?? ''));
        $provider = $providerId !== ''
            ? $this->entityManager->getEntityById('OAuthProvider', $providerId)
            : null;

        if (
            !$provider ||
            !$provider->get('isActive') ||
            $provider->get('provider') !== SeedOAuthProviderGoogleDataManager::PROVIDER_DISCRIMINATOR
        ) {
            throw new RuntimeException('OAuth provider mismatch.');
        }

        $tenantIds = $this->tenantResolver->resolveAllFromTeamIds(
            array_values($account->getLinkMultipleIdList('teams')),
        );

        if (count($tenantIds) !== 1 || $tenantIds[0] !== $tenantId) {
            throw new RuntimeException('OAuth tenant mismatch.');
        }

        return [$destination, $mapping, $oAuthAccountId];
    }

    private function isDue(GoogleAdsConversionUpload $upload): bool
    {
        $status = $upload->get('status');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($status === GoogleAdsConversionUpload::STATUS_RETRY_SCHEDULED) {
            $nextAttemptAt = $upload->get('nextAttemptAt');

            if (is_string($nextAttemptAt) && $nextAttemptAt !== '') {
                $due = DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i:s',
                    $nextAttemptAt,
                    new DateTimeZone('UTC'),
                );

                if ($due instanceof DateTimeImmutable && $due > $now) {
                    return false;
                }
            }
        }

        if ($status === GoogleAdsConversionUpload::STATUS_PROCESSING) {
            $lastAttemptAt = $upload->get('lastAttemptAt');

            if (is_string($lastAttemptAt) && $lastAttemptAt !== '') {
                $started = DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i:s',
                    $lastAttemptAt,
                    new DateTimeZone('UTC'),
                );

                if ($started instanceof DateTimeImmutable && $started->add(new DateInterval('PT15M')) > $now) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function decryptRequest(
        GoogleAdsConversionUpload $upload,
        GoogleAdsDestination $destination,
    ): array {
        $encrypted = $upload->get('encryptedEventData');

        if (!is_string($encrypted) || $encrypted === '') {
            throw new RuntimeException('Encrypted payload missing.');
        }

        try {
            $request = json_decode($this->crypt->decrypt($encrypted), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Encrypted payload JSON is invalid.', 0, $e);
        }

        if (
            !is_array($request) ||
            count($request['destinations'] ?? []) !== 1 ||
            count($request['events'] ?? []) !== 1 ||
            ($request['encoding'] ?? null) !== 'HEX' ||
            ($request['validateOnly'] ?? null) !== (bool) $upload->get('validateOnly')
        ) {
            throw new RuntimeException('Encrypted payload shape is invalid.');
        }

        $requestDestination = $request['destinations'][0] ?? null;
        $event = $request['events'][0] ?? null;

        if (
            !is_array($requestDestination) ||
            !is_array($event) ||
            ($requestDestination['operatingAccount']['accountType'] ?? null) !== 'GOOGLE_ADS' ||
            ($requestDestination['operatingAccount']['accountId'] ?? null)
                !== (string) $destination->get('operatingAccountId') ||
            ($event['transactionId'] ?? null) !== $upload->get('transactionId') ||
            ($event['eventSource'] ?? null) !== 'WEB'
        ) {
            throw new RuntimeException('Encrypted payload destination or event mismatch.');
        }

        $loginAccountId = trim((string) ($destination->get('loginAccountId') ?? ''));
        $requestLoginId = $requestDestination['loginAccount']['accountId'] ?? '';

        if ($requestLoginId !== $loginAccountId) {
            throw new RuntimeException('Encrypted payload login account mismatch.');
        }

        $mapping = $this->entityManager->getEntityById(
            GoogleAdsConversionMapping::ENTITY_TYPE,
            (string) $upload->get('mappingId'),
        );

        if (
            !$mapping instanceof GoogleAdsConversionMapping ||
            ($requestDestination['productDestinationId'] ?? null)
                !== (string) $mapping->get('conversionActionId')
        ) {
            throw new RuntimeException('Encrypted payload conversion action mismatch.');
        }

        return $request;
    }

    private function skip(GoogleAdsConversionUpload $upload, string $code, string $message): void
    {
        $upload->set([
            'status' => GoogleAdsConversionUpload::STATUS_SKIPPED,
            'nextAttemptAt' => null,
            'errorCode' => $code,
            'errorMessage' => $message,
        ]);
        $this->saveUpload($upload);
    }

    private function failPermanent(
        GoogleAdsConversionUpload $upload,
        string $code,
        string $message,
    ): void {
        $upload->set([
            'status' => GoogleAdsConversionUpload::STATUS_FAILED_PERMANENT,
            'nextAttemptAt' => null,
            'errorCode' => $code,
            'errorMessage' => $message,
        ]);
        $this->saveUpload($upload);
    }

    private function saveUpload(GoogleAdsConversionUpload $upload): void
    {
        // Status-only updates must never run hooks that could touch the payload.
        $this->entityManager->saveEntity($upload, ['skipHooks' => true, 'silent' => true]);
    }

    private function updateDestination(
        GoogleAdsDestination $destination,
        bool $success,
        ?string $error,
    ): void {
        $destination->set([
            'lastUploadAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s'),
            'lastUploadStatus' => $success
                ? GoogleAdsDestination::STATUS_SUCCESS
                : GoogleAdsDestination::STATUS_FAILED,
            'lastUploadError' => $success ? null : mb_substr((string) $error, 0, 2000),
        ]);

        try {
            $this->entityManager->saveEntity(
                $destination,
                ['skipHooks' => true, 'silent' => true],
            );
        } catch (Throwable) {
            $this->log->warning(
                'Google Ads destination status update failed for destination=' . $destination->getId(),
            );
        }
    }
}
