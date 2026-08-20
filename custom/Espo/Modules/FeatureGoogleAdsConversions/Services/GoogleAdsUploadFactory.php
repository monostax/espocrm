<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Crypt;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionMapping;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionUpload;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsDestination;
use Espo\Modules\FeatureGoogleAdsConversions\Rebuild\SeedOAuthProviderGoogleDataManager;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use JsonException;
use RuntimeException;

/**
 * Creates the durable upload row and freezes its Data Manager request.
 */
class GoogleAdsUploadFactory
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantResolver $tenantResolver,
        private Crypt $crypt,
        private GoogleAdsAttributionResolver $attributionResolver,
        private GoogleAdsUserDataNormalizer $userDataNormalizer,
        private GoogleAdsEventBuilder $eventBuilder,
    ) {}

    /**
     * Returns null when this exact stage-entry was already materialized.
     */
    public function createForOpportunity(
        Opportunity $opportunity,
        GoogleAdsConversionMapping $mapping,
        ?string $stageFromId,
        string $stageToId,
        DateTimeImmutable $eventTime,
        bool $validateOnly = false,
    ): ?GoogleAdsConversionUpload {
        $destination = $this->assertConfiguration($opportunity, $mapping, $stageToId);
        $tenantId = (string) $opportunity->get('tenantId');
        $idempotencyKey = hash('sha256', implode('|', [
            $tenantId,
            $destination->getId(),
            $mapping->getId(),
            $opportunity->getId(),
            $stageFromId ?? 'create',
            $stageToId,
            $eventTime->format('Y-m-d\TH:i:s.uP'),
            $validateOnly ? 'validate' : 'send',
        ]));

        if ($this->findByIdempotencyKey($idempotencyKey)) {
            return null;
        }

        $transactionId = 'mstx-' . $idempotencyKey;
        $contact = $this->contact($opportunity, $tenantId);
        $attribution = $contact !== null
            ? $this->attributionResolver->resolve(
                $contact,
                $tenantId,
                (int) ($mapping->get('lookbackDays') ?: 30),
                $this->attributionAnchor($opportunity, $eventTime),
            )
            : $this->emptyAttribution();
        $userData = $contact !== null
            && $mapping->get('includeUserData')
            && $attribution['consent']['adUserData'] === 'Granted'
                ? $this->userDataNormalizer->normalize($contact)
                : [];
        $event = $this->eventBuilder->build(
            $opportunity,
            $mapping,
            $attribution,
            $userData,
            $transactionId,
            $eventTime,
        );

        /** @var GoogleAdsConversionUpload $upload */
        $upload = $this->entityManager->getNewEntity(GoogleAdsConversionUpload::ENTITY_TYPE);
        $upload->set([
            'name' => mb_substr(
                'Google Ads conversion - ' . (string) ($mapping->get('name') ?: $mapping->getId()),
                0,
                200,
            ),
            'destinationId' => $destination->getId(),
            'mappingId' => $mapping->getId(),
            'subjectType' => Opportunity::ENTITY_TYPE,
            'subjectId' => $opportunity->getId(),
            'opportunityId' => $opportunity->getId(),
            'contactId' => $contact?->getId(),
            'stageFromId' => $stageFromId,
            'stageToId' => $stageToId,
            'eventTime' => $eventTime->format('Y-m-d H:i:s'),
            'transactionId' => $transactionId,
            'idempotencyKey' => $idempotencyKey,
            'status' => $event === null
                ? GoogleAdsConversionUpload::STATUS_SKIPPED
                : GoogleAdsConversionUpload::STATUS_PENDING,
            'validateOnly' => $validateOnly,
            'attributionType' => $event === null
                ? 'None'
                : ($attribution['attributionType'] !== 'None'
                    ? $attribution['attributionType']
                    : 'UserData'),
            'identifierFingerprint' => $this->fingerprint($attribution['clickIds'], $userData),
            'value' => $event['conversionValue'] ?? null,
            'currency' => $event['currency'] ?? '',
            'eventSummary' => [
                'eventSource' => 'WEB',
                'hasClickIdentifiers' => $attribution['clickIds'] !== [],
                'userIdentifierCount' => count($userData['userIdentifiers'] ?? []),
                'adUserDataConsent' => $attribution['consent']['adUserData'],
                'adPersonalizationConsent' => $attribution['consent']['adPersonalization'],
            ],
            'tenantId' => $tenantId,
        ]);

        if ($event === null) {
            $upload->set('errorCode', 'NO_USABLE_IDENTIFIER');
            $upload->set('errorMessage', 'No recent click attribution or permitted user identifier was available.');
        } else {
            $request = [
                'destinations' => [$this->destination($destination, $mapping)],
                'events' => [$event],
                'encoding' => 'HEX',
                'validateOnly' => $validateOnly,
            ];

            try {
                $json = json_encode(
                    $request,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
            } catch (JsonException $e) {
                throw new RuntimeException('Unable to encode the Google Ads conversion payload.', 0, $e);
            }

            $upload->set('encryptedEventData', $this->crypt->encrypt($json));
            $upload->set('nextAttemptAt', $eventTime->format('Y-m-d H:i:s'));
        }

        // Keep FieldProcessing enabled so inherited teamsIds are persisted.
        // The unique idempotency index remains the final concurrency guard.
        $this->entityManager->saveEntity($upload, ['silent' => true]);

        return $upload;
    }

    private function assertConfiguration(
        Opportunity $opportunity,
        GoogleAdsConversionMapping $mapping,
        string $stageToId,
    ): GoogleAdsDestination {
        $tenantId = trim((string) ($opportunity->get('tenantId') ?? ''));
        $destinationId = trim((string) ($mapping->get('destinationId') ?? ''));
        $destination = $destinationId !== ''
            ? $this->entityManager->getEntityById(GoogleAdsDestination::ENTITY_TYPE, $destinationId)
            : null;

        if (!$destination instanceof GoogleAdsDestination) {
            throw new RuntimeException('Google Ads destination is missing.');
        }

        if (!$mapping->get('isActive') || !$destination->get('isActive')) {
            throw new RuntimeException('Google Ads mapping or destination is inactive.');
        }

        if (
            $tenantId === '' ||
            $tenantId !== (string) $mapping->get('tenantId') ||
            $tenantId !== (string) $destination->get('tenantId')
        ) {
            throw new RuntimeException('Google Ads conversion tenant mismatch.');
        }

        if (
            (string) $mapping->get('funnelId') !== (string) $opportunity->get('funnelId') ||
            (string) $mapping->get('opportunityStageId') !== $stageToId ||
            (string) $opportunity->get('opportunityStageId') !== $stageToId
        ) {
            throw new RuntimeException('Google Ads conversion mapping does not match the entered stage.');
        }

        if (
            preg_match('/^[0-9]{10}$/', (string) $destination->get('operatingAccountId')) !== 1 ||
            (
                trim((string) ($destination->get('loginAccountId') ?? '')) !== '' &&
                preg_match('/^[0-9]{10}$/', (string) $destination->get('loginAccountId')) !== 1
            ) ||
            preg_match('/^[0-9]+$/', (string) $mapping->get('conversionActionId')) !== 1
        ) {
            throw new RuntimeException('Google Ads account or conversion action is invalid.');
        }

        $this->assertOAuthAccount($destination, $tenantId);

        return $destination;
    }

    private function assertOAuthAccount(GoogleAdsDestination $destination, string $tenantId): void
    {
        $accountId = trim((string) ($destination->get('oAuthAccountId') ?? ''));
        $account = $accountId !== ''
            ? $this->entityManager->getEntityById('OAuthAccount', $accountId)
            : null;

        if (!$account instanceof CoreEntity) {
            throw new RuntimeException('Google Data Manager OAuth account is missing.');
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
            throw new RuntimeException('OAuth account is not backed by the active Google Data Manager provider.');
        }

        $tenantIds = $this->tenantResolver->resolveAllFromTeamIds(
            array_values($account->getLinkMultipleIdList('teams')),
        );

        if (count($tenantIds) !== 1 || $tenantIds[0] !== $tenantId) {
            throw new RuntimeException('OAuth account tenant does not match the Google Ads destination.');
        }
    }

    private function contact(Opportunity $opportunity, string $tenantId): ?Entity
    {
        $contactId = $opportunity->get('contactId');

        if (!is_string($contactId) || $contactId === '') {
            return null;
        }

        return $this->entityManager
            ->getRDBRepository('Contact')
            ->where(['id' => $contactId, 'tenantId' => $tenantId, 'deleted' => false])
            ->findOne();
    }

    private function attributionAnchor(
        Opportunity $opportunity,
        DateTimeImmutable $eventTime,
    ): DateTimeImmutable {
        $createdAt = $opportunity->get('createdAt');

        if (is_string($createdAt) && $createdAt !== '') {
            $parsed = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $createdAt,
                new DateTimeZone('UTC'),
            );

            if ($parsed instanceof DateTimeImmutable) {
                return $parsed;
            }
        }

        return $eventTime;
    }

    /**
     * @return array{
     *   clickIds: array{},
     *   attributionType: string,
     *   consent: array{adUserData: string, adPersonalization: string},
     *   trackingEventId: null
     * }
     */
    private function emptyAttribution(): array
    {
        return [
            'clickIds' => [],
            'attributionType' => 'None',
            'consent' => ['adUserData' => 'Unknown', 'adPersonalization' => 'Unknown'],
            'trackingEventId' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function destination(
        GoogleAdsDestination $destination,
        GoogleAdsConversionMapping $mapping,
    ): array {
        $value = [
            'operatingAccount' => [
                'accountType' => 'GOOGLE_ADS',
                'accountId' => (string) $destination->get('operatingAccountId'),
            ],
            'productDestinationId' => (string) $mapping->get('conversionActionId'),
        ];
        $loginAccountId = trim((string) ($destination->get('loginAccountId') ?? ''));

        if ($loginAccountId !== '') {
            $value['loginAccount'] = [
                'accountType' => 'GOOGLE_ADS',
                'accountId' => $loginAccountId,
            ];
        }

        return $value;
    }

    /**
     * @param array<string, string> $clickIds
     * @param array<string, mixed> $userData
     */
    private function fingerprint(array $clickIds, array $userData): ?string
    {
        if ($clickIds === [] && $userData === []) {
            return null;
        }

        ksort($clickIds);

        return hash('sha256', json_encode([$clickIds, $userData]) ?: '');
    }

    private function findByIdempotencyKey(string $key): ?GoogleAdsConversionUpload
    {
        $entity = $this->entityManager
            ->getRDBRepository(GoogleAdsConversionUpload::ENTITY_TYPE)
            ->where(['idempotencyKey' => $key, 'deleted' => false])
            ->findOne();

        return $entity instanceof GoogleAdsConversionUpload ? $entity : null;
    }
}
