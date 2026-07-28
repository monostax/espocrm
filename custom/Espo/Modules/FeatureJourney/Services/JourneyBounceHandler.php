<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\InjectableFactory;
use Espo\Core\Mail\Account\GroupAccount\BouncedRecognizer;
use Espo\Core\Mail\Message;
use Espo\Core\Utils\Log;
use Espo\Entities\Email;
use Espo\Entities\EmailAddress;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Repositories\EmailAddress as EmailAddressRepository;
use Throwable;

/**
 * Group-IMAP bounce (DSN) → journey signal `email_bounced`.
 *
 * Correlation is Message-ID based (same family as reply tracking):
 *   outbound journey Email.messageId / journeyToken (minted into Message-ID)
 *   ← DSN Original-Message-ID / nested Message-ID
 *   → Email.journeyRecordId → dispatch
 */
class JourneyBounceHandler
{
    private const RECORDER_FQCN = 'Espo\\Modules\\FeatureTrackingEvent\\Services\\InternalEventRecorder';

    /** @var array<string, true> */
    private static array $fired = [];

    public function __construct(
        private EntityManager $entityManager,
        private BouncedRecognizer $bouncedRecognizer,
        private JourneySignalDispatcher $dispatcher,
        private TenantResolver $tenantResolver,
        private InjectableFactory $injectableFactory,
        private Log $log,
    ) {}

    /**
     * @return bool true when a journey outbound was matched (caller may skip importing DSN)
     */
    public function process(Message $message, bool $isHard): bool
    {
        try {
            return $this->processInternal($message, $isHard);
        } catch (Throwable $e) {
            $this->log->warning('JourneyBounceHandler: ' . $e->getMessage());

            return false;
        }
    }

    private function processInternal(Message $message, bool $isHard): bool
    {
        $original = $this->resolveOriginalEmail($message);
        if (!$original) {
            return false;
        }

        $journeyRecordId = (string) ($original->get('journeyRecordId') ?? '');
        if ($journeyRecordId === '') {
            return false;
        }

        $emailId = $original->hasId() ? (string) $original->getId() : '';
        $dedupeKey = $journeyRecordId . ':' . $emailId . ':' . ($isHard ? 'h' : 's');
        if (isset(self::$fired[$dedupeKey])) {
            return true;
        }
        self::$fired[$dedupeKey] = true;

        $toAddress = $this->bouncedRecognizer->extractFinalRecipient($message);
        if ($toAddress === null || $toAddress === '') {
            $toAddress = $this->firstAddressFromEmailTo($original);
        }

        if ($isHard && $toAddress) {
            $this->markAddressInvalid($toAddress);
        }

        [$targetType, $targetId, $contactId] = $this->resolveTarget($original, $journeyRecordId);
        if ($targetType === null || $targetId === null) {
            $this->log->info(
                "JourneyBounceHandler: journeyRecord {$journeyRecordId} bounce without target; skip signal."
            );

            return true;
        }

        $target = $this->entityManager->getEntityById($targetType, $targetId);
        if (!$target) {
            return true;
        }

        $tenantId = $this->tenantResolver->resolveTenantIdForEntity($original)
            ?: $this->tenantResolver->resolveTenantIdForEntity($target);

        if (!$tenantId) {
            $this->log->info("JourneyBounceHandler: email {$emailId} no tenant; skip signal.");

            return true;
        }

        $token = $this->resolveToken($original);
        $statusCode = $this->bouncedRecognizer->extractStatus($message);
        $journeyId = (string) ($original->get('journeyId') ?? '');

        $properties = [
            'emailId' => $emailId !== '' ? $emailId : null,
            'isHard' => $isHard,
            'statusCode' => $statusCode,
            'toAddress' => $toAddress,
            'journeyToken' => $token,
            'journeyId' => $journeyId !== '' ? $journeyId : null,
            'journeyRecordId' => $journeyRecordId,
            'messageId' => $original->get('messageId'),
            'targetType' => $targetType,
            'targetId' => $targetId,
        ];

        $detail = $isHard ? 'hard bounce' : 'soft bounce';
        if ($statusCode) {
            $detail .= ' ' . $statusCode;
        }

        $eventId = $this->tryRecordTracking(
            $tenantId,
            $contactId,
            $targetType,
            $targetId,
            $properties,
            $detail,
        );

        if ($eventId === null) {
            $this->dispatcher->dispatch(
                $tenantId,
                JourneyEmailToken::CODE_BOUNCED,
                $target,
                $properties,
                null,
            );
        }

        return true;
    }

    private function resolveOriginalEmail(Message $message): ?Entity
    {
        $messageIds = $this->bouncedRecognizer->extractOriginalMessageIds($message);

        foreach ($messageIds as $messageId) {
            $email = $this->findEmailByMessageId($messageId);
            if ($email && $this->isJourneyEmail($email)) {
                return $email;
            }

            $token = JourneyEmailToken::extractTokenFromMessageId($messageId);
            if ($token === null) {
                continue;
            }

            $email = $this->findEmailByJourneyToken($token);
            if ($email && $this->isJourneyEmail($email)) {
                return $email;
            }
        }

        return null;
    }

    private function findEmailByMessageId(string $messageId): ?Entity
    {
        $candidates = [$messageId];
        $bare = trim($messageId, "<> \t");
        if ($bare !== $messageId) {
            $candidates[] = $bare;
        } else {
            $candidates[] = '<' . $bare . '>';
        }

        foreach ($candidates as $id) {
            $email = $this->entityManager
                ->getRDBRepository(Email::ENTITY_TYPE)
                ->where(['messageId' => $id])
                ->findOne();

            if ($email) {
                return $email;
            }
        }

        return null;
    }

    private function findEmailByJourneyToken(string $token): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository(Email::ENTITY_TYPE)
            ->where(['journeyToken' => $token])
            ->order('createdAt', 'DESC')
            ->findOne();
    }

    private function isJourneyEmail(Entity $email): bool
    {
        $recordId = $email->get('journeyRecordId');

        return is_string($recordId) && $recordId !== '';
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
    private function resolveTarget(Entity $original, string $journeyRecordId): array
    {
        $parentType = $original->get('parentType') ? (string) $original->get('parentType') : '';
        $parentId = $original->get('parentId') ? (string) $original->get('parentId') : '';

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

        $record = $this->entityManager->getEntityById('JourneyRecord', $journeyRecordId);
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

    private function markAddressInvalid(string $address): void
    {
        /** @var EmailAddressRepository $repo */
        $repo = $this->entityManager->getRepository(EmailAddress::ENTITY_TYPE);
        $entity = $repo->getByAddress($address);

        if (!$entity) {
            return;
        }

        if ($entity->isInvalid()) {
            return;
        }

        $entity->setInvalid(true);
        $this->entityManager->saveEntity($entity);
    }

    private function firstAddressFromEmailTo(Entity $email): ?string
    {
        $to = $email->get('to');
        if (!is_string($to) || trim($to) === '') {
            return null;
        }

        $parts = preg_split('/[;,]/', $to) ?: [];
        foreach ($parts as $part) {
            $addr = strtolower(trim($part, " \t\"'<>"));
            if ($addr !== '' && str_contains($addr, '@')) {
                return $addr;
            }
        }

        return null;
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

            $id = $recorder->record($tenantId, JourneyEmailToken::CODE_BOUNCED, [
                'contactId' => $contactId,
                'parentType' => $parentType,
                'parentId' => $parentId,
                'properties' => $properties,
                'detail' => $detail,
            ]);

            return is_string($id) && $id !== '' ? $id : null;
        } catch (Throwable $e) {
            $this->log->warning('JourneyBounceHandler: tracking record failed: ' . $e->getMessage());

            return null;
        }
    }
}
