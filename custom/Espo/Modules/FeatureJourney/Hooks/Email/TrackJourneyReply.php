<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\Email;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Entities\Email;
use Espo\Modules\FeatureJourney\Services\JourneyEmailToken;
use Espo\Modules\FeatureJourney\Services\JourneySignalDispatcher;
use Espo\Modules\FeatureJourney\Services\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * Inbound reply → journey signal `email_replied`.
 *
 * Correlation is Message-ID based (not custom X-headers — clients drop those):
 * outbound journey send uses Message-ID `<jrn.{token}.{rand}@journey.monostax>`;
 * Espo importer matches In-Reply-To → sets `repliedId` on the inbound Email.
 *
 * @implements AfterSave<Entity>
 */
class TrackJourneyReply implements AfterSave
{
    public static int $order = 60;

    private const RECORDER_FQCN = 'Espo\\Modules\\FeatureTrackingEvent\\Services\\InternalEventRecorder';

    /** @var array<string, true> */
    private static array $fired = [];

    public function __construct(
        private EntityManager $entityManager,
        private JourneySignalDispatcher $dispatcher,
        private TenantResolver $tenantResolver,
        private InjectableFactory $injectableFactory,
        private Log $log,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->getEntityType() !== Email::ENTITY_TYPE) {
            return;
        }

        try {
            $this->process($entity);
        } catch (Throwable $e) {
            $this->log->warning('TrackJourneyReply: ' . $e->getMessage());
        }
    }

    private function process(Entity $entity): void
    {
        $emailId = $entity->hasId() ? (string) $entity->getId() : '';
        if ($emailId === '' || isset(self::$fired[$emailId])) {
            return;
        }

        $status = (string) ($entity->get('status') ?? '');
        if ($status !== Email::STATUS_ARCHIVED) {
            return;
        }

        $repliedId = $entity->get('repliedId') ? (string) $entity->get('repliedId') : '';
        if ($repliedId === '') {
            return;
        }

        $isNew = $entity->isNew();
        $repliedJustSet = $entity->isAttributeChanged('repliedId');
        $statusJustArchived = $entity->isAttributeChanged('status');

        if (!$isNew && !$repliedJustSet && !$statusJustArchived) {
            return;
        }

        $original = $this->entityManager->getEntityById(Email::ENTITY_TYPE, $repliedId);
        if (!$original) {
            return;
        }

        $token = $this->resolveToken($original);
        if ($token === null) {
            return;
        }

        self::$fired[$emailId] = true;

        $journeyId = (string) ($original->get('journeyId') ?? '');
        $journeyRecordId = (string) ($original->get('journeyRecordId') ?? '');

        [$targetType, $targetId, $contactId] = $this->resolveTarget($entity, $original);

        if ($targetType === null || $targetId === null) {
            $this->log->info("TrackJourneyReply: email {$emailId} journey reply without target; skip.");

            return;
        }

        $target = $this->entityManager->getEntityById($targetType, $targetId);
        if (!$target) {
            return;
        }

        $tenantId = $this->tenantResolver->resolveTenantIdForEntity($original)
            ?: $this->tenantResolver->resolveTenantIdForEntity($target)
            ?: $this->tenantResolver->resolveTenantIdForEntity($entity);

        if (!$tenantId) {
            $this->log->info("TrackJourneyReply: email {$emailId} no tenant; skip.");

            return;
        }

        $fromAddress = null;
        if ($entity instanceof Email) {
            try {
                $fromAddress = $entity->getFromAddress();
            } catch (Throwable) {
                $fromAddress = null;
            }
        }

        $properties = [
            'emailId' => $emailId,
            'repliedEmailId' => $repliedId,
            'journeyToken' => $token,
            'journeyId' => $journeyId !== '' ? $journeyId : null,
            'journeyRecordId' => $journeyRecordId !== '' ? $journeyRecordId : null,
            'subject' => $entity->get('name'),
            'fromAddress' => $fromAddress,
            'targetType' => $targetType,
            'targetId' => $targetId,
        ];

        $detail = is_string($entity->get('name')) ? (string) $entity->get('name') : null;
        $eventId = $this->tryRecordTracking(
            $tenantId,
            $contactId,
            $targetType,
            $targetId,
            $properties,
            $detail,
        );

        // TrackingEvent afterSave → DispatchToJourneys. If tracking skipped, dispatch here.
        if ($eventId === null) {
            $this->dispatcher->dispatch(
                $tenantId,
                JourneyEmailToken::CODE_REPLIED,
                $target,
                $properties,
                null,
            );
        }
    }

    private function resolveToken(Entity $original): ?string
    {
        $stored = $original->get('journeyToken');
        if (is_string($stored) && JourneyEmailToken::isValidToken($stored)) {
            return strtolower($stored);
        }

        return JourneyEmailToken::extractTokenFromMessageId(
            $original->get('messageId') ? (string) $original->get('messageId') : null
        );
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function resolveTarget(Entity $inbound, Entity $original): array
    {
        foreach ([$original, $inbound] as $email) {
            $parentType = $email->get('parentType') ? (string) $email->get('parentType') : '';
            $parentId = $email->get('parentId') ? (string) $email->get('parentId') : '';

            if (
                $parentType !== '' &&
                $parentId !== '' &&
                in_array($parentType, ['Contact', 'Lead', 'Account'], true)
            ) {
                return [
                    $parentType,
                    $parentId,
                    $parentType === 'Contact' ? $parentId : null,
                ];
            }
        }

        $recordId = $original->get('journeyRecordId') ? (string) $original->get('journeyRecordId') : '';
        if ($recordId === '') {
            return [null, null, null];
        }

        $record = $this->entityManager->getEntityById('JourneyRecord', $recordId);
        if (!$record) {
            return [null, null, null];
        }

        $tType = $record->get('targetType') ? (string) $record->get('targetType') : '';
        $tId = $record->get('targetId') ? (string) $record->get('targetId') : '';

        if ($tType === '' || $tId === '') {
            return [null, null, null];
        }

        return [$tType, $tId, $tType === 'Contact' ? $tId : null];
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function tryRecordTracking(
        string $tenantId,
        ?string $contactId,
        string $parentType,
        string $parentId,
        array $properties,
        ?string $detail,
    ): ?string {
        if (!class_exists(self::RECORDER_FQCN)) {
            return null;
        }

        try {
            /** @var object $recorder */
            $recorder = $this->injectableFactory->create(self::RECORDER_FQCN);

            if (!method_exists($recorder, 'record')) {
                return null;
            }

            $id = $recorder->record($tenantId, JourneyEmailToken::CODE_REPLIED, [
                'contactId' => $contactId,
                'parentType' => $parentType,
                'parentId' => $parentId,
                'properties' => $properties,
                'detail' => $detail,
            ]);

            return is_string($id) && $id !== '' ? $id : null;
        } catch (Throwable $e) {
            $this->log->warning('TrackJourneyReply: tracking record failed: ' . $e->getMessage());

            return null;
        }
    }
}
