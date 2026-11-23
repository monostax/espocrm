<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\Chatwoot\Tools\PhoneNormalizer;
use Espo\Modules\FeatureJourney\Entities\JourneyTransition;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Review belongs to the enrollment, never to the global phone or company. */
class AccountReplyReview
{
    public function __construct(private EntityManager $em) {}

    public static function state(Entity $record): array
    {
        return json_decode(json_encode($record->get('replyReviewState')) ?: 'null', true) ?: [];
    }

    /** True when the ordinary reply exit must not run. Both inbound paths use this before dispatch. */
    public function receive(string $tenant, string $accountId, string $targetId, array $p): bool
    {
        if (!$accountId || empty($p['journeyRecordId']) || empty($p['chatwootConversationId']) ||
            !in_array($p['channelType'] ?? null, [...JourneyWhatsAppOutbound::CHANNELS_MESSAGE, 'Channel::Whatsapp', 'whatsapp'], true)) {
            return false;
        }
        return $this->em->getTransactionManager()->run(function () use ($tenant, $accountId, $targetId, $p) {
            $record = $this->em->getRDBRepository('JourneyRecord')->where([
                'id' => $p['journeyRecordId'], 'tenantId' => $tenant, 'targetType' => 'Account', 'targetId' => $targetId,
            ])->forUpdate()->findOne();
            if (!$record) {
                return false;
            }
            $journey = $this->em->getEntityById('Journey', $record->get('journeyId'));
            if (!$journey || $journey->get('tenantId') !== $tenant || !$journey->get('reviewAccountWhatsAppReplies') ||
                $journey->get('status') !== 'Active') {
                return false;
            }
            if (!in_array($record->get('status'), ['Active', 'Processing', 'Paused'], true)) {
                return true;
            }
            $created = $p['messageCreatedAt'] ?? null;
            $ts = is_numeric($created) ? (int) $created : ($created ? strtotime((string) $created) : false);
            if ($ts && gmdate('Y-m-d H:i:s', $ts) < $record->get('createdAt')) {
                return true;
            }
            $state = self::state($record);
            $key = $accountId . ':' . $p['chatwootConversationId'];
            $id = (string) ($p['chatwootMessageId'] ?? $p['messageId'] ?? '');
            $phone = PhoneNormalizer::normalize($p['replyPhone'] ?? null);
            if (isset($state['excludedConversations'][$key]) || in_array($phone, $state['excluded'] ?? [], true)) {
                return true;
            }
            if ($id !== '' && isset($state['seen'][$key . ':' . $id])) {
                // Sync can fill the phone that was absent at webhook time.
                if ($phone && isset($state['pending'][$key]) && !$state['pending'][$key]['phone']) {
                    $state['pending'][$key]['phone'] = $phone;
                    $this->saveState($record, $state);
                }
                return true;
            }
            if ($id !== '') {
                $state['seen'][$key . ':' . $id] = true;
            }
            $state['pending'][$key] = ['phone' => $phone ?: ($state['pending'][$key]['phone'] ?? null), 'message' => $id];
            $state['revision'] = bin2hex(random_bytes(12));
            if (!$record->get('replyPausedAt')) {
                $record->set('replyPausedAt', gmdate('Y-m-d H:i:s'));
            }
            if (!$record->get('claimedAt')) {
                $record->set('status', 'Paused');
            }
            $this->saveState($record, $state);
            $this->log($record, 'reply_received', null, ['conversation' => $key, 'message' => $id]);
            return true;
        });
    }

    /** Only return the data necessary to review this enrollment. */
    public static function summary(Entity $record): array
    {
        $s = self::state($record);
        $pending = [];
        foreach ($s['pending'] ?? [] as $conversation => $item) {
            $parts = explode(':', (string) $conversation, 2);
            $rawId = $parts[1] ?? '';
            $pending[$conversation] = [
                'phone' => $item['phone'] ? '•••• ' . substr($item['phone'], -4) : null,
                'conversationId' => $rawId !== '' && ctype_digit($rawId) ? (int) $rawId : null,
            ];
        }
        return ['status' => $record->get('status'), 'replyPausedAt' => $record->get('replyPausedAt'),
            'revision' => $s['revision'] ?? '', 'pending' => $pending,
            'excluded' => array_map(static fn ($phone) => '•••• ' . substr((string) $phone, -4), $s['excluded'] ?? []),
            'catchUpUntilStageId' => JourneyCatchUp::active($s) ? ($s['catchUp']['until'] ?? null) : null];
    }

    /**
     * The company's contacts a new number can be added to when resuming. `wrongNumber`
     * marks the contacts whose number was marked wrong, the usual place for the new one.
     *
     * @return array{contacts: list<array{contactId: string, contactName: string, wrongNumber: bool}>, canAddNumber: bool}
     */
    public function contacts(
        Entity $record,
        Acl $acl,
        JourneyTargetPeople $people,
        JourneyWhatsAppOutbound $outbound,
    ): array {
        $tenant = (string) ($record->get('tenantId') ?? '');
        $target = $record->get('targetType') === 'Account' && $record->get('targetId')
            ? $this->em->getEntityById('Account', (string) $record->get('targetId'))
            : null;
        $canAdd = $acl->checkScope('Contact', 'edit') && $acl->checkField('Contact', 'phoneNumber', 'edit');
        if (!$tenant || !$target || !$acl->check($target, 'read') ||
            !$acl->checkScope('Contact', 'read') || !$acl->checkField('Contact', 'phoneNumber', 'read')) {
            return ['contacts' => [], 'canAddNumber' => false];
        }
        $excluded = self::state($record)['excluded'] ?? [];
        $canReadName = $acl->checkField('Contact', 'name', 'read');
        $list = [];
        foreach ($people->forTarget($target, $tenant) as $person) {
            if ($person->getEntityType() !== 'Contact' || !$acl->check($person, 'read') ||
                $outbound->isOptedOut($person)) {
                continue;
            }
            $name = $canReadName ? trim((string) ($person->get('name') ?? '')) : '';
            if ($name === '' && $canReadName) {
                $name = trim(((string) ($person->get('firstName') ?? '')) . ' ' . ((string) ($person->get('lastName') ?? '')));
            }
            $list[] = [
                'contactId' => (string) $person->getId(),
                'contactName' => $name,
                'wrongNumber' => array_intersect($outbound->resolvePhones($person), $excluded) !== [],
            ];
        }
        usort($list, static fn ($a, $b) => (int) $b['wrongNumber'] <=> (int) $a['wrongNumber']);

        return ['contacts' => $list, 'canAddNumber' => $canAdd && $list !== []];
    }

    public function decide(string $id, object $input, string $actor, Acl $acl, JourneyTargetPeople $people,
        JourneyWhatsAppOutbound $outbound, TransitionExecutor $executor): array
    {
        return $this->em->getTransactionManager()->run(function () use ($id, $input, $actor, $acl, $people, $outbound, $executor) {
            $r = $this->em->getRDBRepository('JourneyRecord')->where(['id' => $id])->forUpdate()->findOne();
            if (!$r || !$acl->check($r, 'edit') || !$acl->checkField('JourneyRecord', 'status', 'edit')) {
                throw new Forbidden();
            }
            $s = self::state($r);
            if ($r->get('status') !== 'Paused' || !$r->get('replyPausedAt') || empty($s['revision']) ||
                $s['revision'] !== ($input->revision ?? null)) {
                throw new Conflict('This review changed. Reopen it before continuing.');
            }
            $j = $this->em->getEntityById('Journey', $r->get('journeyId'));
            $tenant = $r->get('tenantId');
            if (!$tenant || !$j || $j->get('tenantId') !== $tenant || $j->get('status') !== 'Active') {
                throw new BadRequest('The parent journey must be active.');
            }
            $decision = $input->decision ?? '';
            if ($decision === 'wrongNumber') {
                $key = (string) ($input->conversation ?? '');
                $phone = $s['pending'][$key]['phone'] ?? null;
                if (!$phone) {
                    throw new BadRequest('The replying phone number has not been identified.');
                }
                $s['excluded'] = array_values(array_unique([...($s['excluded'] ?? []), $phone]));
                foreach ($s['pending'] as $conversation => $item) {
                    if ($item['phone'] === $phone) {
                        $s['excludedConversations'][$conversation] = true;
                    }
                }
                $s['pending'] = array_filter($s['pending'], static fn ($item) => $item['phone'] !== $phone);
                $s = JourneyCatchUp::drop($s, $phone);
                $s['revision'] = bin2hex(random_bytes(12));
                $this->saveState($r, $s);
                $this->log($r, $decision, $actor, ['conversation' => $key]);
            } elseif ($decision === 'resume') {
                if (!empty($s['pending']) || empty($s['excluded'])) {
                    throw new BadRequest('Review every reply before resuming.');
                }
                if (trim((string) ($input->phone ?? '')) !== '') {
                    // A new number starts from the Entry stage; the others wait where they are.
                    [$phone, $contact] = $this->addNumber($r, $s, (string) $input->phone,
                        (string) ($input->contactId ?? ''), $tenant, $acl, $people, $outbound);
                    $s = JourneyCatchUp::start($s, (string) $r->get('currentStageId'), $r->get('enteredStageAt'),
                        $this->visitedStages($r), $phone, $contact->getId());
                    unset($s['heldSeconds'], $s['heldWeekdaySeconds'], $s['heldStage'], $s['heldEntered']);
                    $r->set(['status' => 'Active', 'replyPausedAt' => null]);
                    $this->saveState($r, $s);
                    $this->log($r, $decision, $actor, ['contactId' => $contact->getId(), 'catchUp' => true]);
                    if (!$executor->restartForCatchUp($id)) {
                        throw new Conflict('The cadence could not restart for the new number. Try again.');
                    }
                    return self::summary($this->em->getEntityById('JourneyRecord', $id));
                }
                if (JourneyCatchUp::abandoned($s)) {
                    // Every added number was wrong: the others continue from the stage they waited at.
                    $from = $r->get('currentStageId');
                    $until = (string) $s['catchUp']['until'];
                    $entered = $s['catchUp']['untilEnteredAt'] ?? null;
                    unset($s['catchUp'], $s['heldSeconds'], $s['heldWeekdaySeconds'], $s['heldStage'], $s['heldEntered']);
                    $r->set(['status' => 'Active', 'replyPausedAt' => null, 'currentStageId' => $until,
                        'enteredStageAt' => $entered ?: gmdate('Y-m-d H:i:s')]);
                    $this->saveState($r, $s);
                    $this->log($r, $decision, $actor, ['catchUpEnded' => true], $from);
                    return self::summary($r);
                }
                // Everyone except the numbers marked wrong continues, with the wait that was left.
                $now = time();
                $start = strtotime($r->get('replyPausedAt') . ' UTC');
                if (($s['heldStage'] ?? null) !== $r->get('currentStageId') ||
                    ($s['heldEntered'] ?? null) !== $r->get('enteredStageAt')) {
                    $s['heldSeconds'] = $s['heldWeekdaySeconds'] = 0;
                }
                $s['heldSeconds'] = ($s['heldSeconds'] ?? 0) + max(0, $now - $start);
                $s['heldWeekdaySeconds'] = ($s['heldWeekdaySeconds'] ?? 0) +
                    PeriodParser::weekdaySeconds($start, $now, $j->get('timeZone') ?: 'UTC');
                $s['heldStage'] = $r->get('currentStageId');
                $s['heldEntered'] = $r->get('enteredStageAt');
                $r->set(['status' => 'Active', 'replyPausedAt' => null]);
                $this->saveState($r, $s);
                $this->log($r, $decision, $actor);
            } elseif ($decision === 'end') {
                // Execute the actual configured Exit edge, including its task cleanup.
                $signal = ['code' => JourneyWhatsAppSignal::CODE_REPLIED, 'payload' => ['journeyRecordId' => $id]];
                $r->set(['status' => 'Active', 'replyPausedAt' => null]);
                $this->saveState($r, $s);
                $edges = [];
                foreach ($this->em->getRDBRepository('JourneyTransition')->where([
                    'journeyId' => $j->getId(), 'isActive' => true,
                ])->find() as $edge) {
                    if (JourneyTransition::appliesToStage($edge, $r->get('currentStageId')) &&
                        in_array($signal['code'], $edge->get('eventCodes') ?: [], true) &&
                        $this->em->getEntityById('JourneyStage', $edge->get('toStageId'))?->get('stageType') === 'Exit') {
                        $edges[] = $edge;
                    }
                }
                usort($edges, [JourneyTransition::class, 'compareForRecord']);
                foreach ($edges as $edge) {
                    if ($executor->execute($id, $edge->getId(), $signal, 'user')) {
                        $this->log($r, $decision, $actor);
                        return self::summary($this->em->getEntityById('JourneyRecord', $id));
                    }
                }
                throw new BadRequest('No matching WhatsApp exit transition could be completed.');
            } else {
                throw new BadRequest('Unknown reply review decision.');
            }
            return self::summary($r);
        });
    }

    /**
     * Put a new mobile number on one of the company's contacts. It must be new to the
     * company: a number already on a contact already gets the cadence.
     *
     * @return array{0: string, 1: Entity}
     */
    private function addNumber(Entity $r, array $s, string $raw, string $contactId, string $tenant, Acl $acl,
        JourneyTargetPeople $people, JourneyWhatsAppOutbound $outbound): array
    {
        $phone = PhoneNormalizer::normalize(trim($raw));
        if (!$phone || !$outbound->isMobile($phone)) {
            throw new BadRequest('Enter a mobile number with area code.');
        }
        if (in_array($phone, $s['excluded'] ?? [], true)) {
            throw new BadRequest('This number was marked wrong for this company.');
        }
        $target = $this->em->getEntityById('Account', (string) $r->get('targetId'));
        if (!$target || !$acl->check($target, 'read')) {
            throw new Forbidden();
        }
        $contact = null;
        // Every contact of the company counts, readable or not: the cadence reaches them all.
        foreach ($people->forTarget($target, $tenant) as $person) {
            if (in_array($phone, $outbound->resolvePhones($person), true)) {
                throw new BadRequest('This number is already on one of the company\'s contacts, so it already gets the cadence.');
            }
            if ($person->getEntityType() === 'Contact' && $person->getId() === $contactId) {
                $contact = $person;
            }
        }
        if (!$contact || !$acl->check($contact, 'read')) {
            throw new BadRequest('Pick one of the company\'s contacts for the new number.');
        }
        if (!$acl->check($contact, 'edit') || !$acl->checkField('Contact', 'phoneNumber', 'edit')) {
            throw new Forbidden('You can\'t edit this contact\'s phone numbers.');
        }
        if ($outbound->isOptedOut($contact)) {
            throw new BadRequest('This contact opted out of WhatsApp.');
        }
        $contact = $this->em->getEntityById('Contact', $contact->getId()) ?? $contact;
        /** @var \Espo\Repositories\PhoneNumber $repo */
        $repo = $this->em->getRepository(\Espo\Entities\PhoneNumber::ENTITY_TYPE);
        $data = $repo->getPhoneNumberData($contact);
        $data[] = (object) ['phoneNumber' => $phone, 'type' => 'Mobile', 'primary' => $data === [],
            'optOut' => false, 'invalid' => false];
        $contact->set('phoneNumberData', $data);
        $this->em->saveEntity($contact);
        if (!in_array($phone, $outbound->resolvePhones($contact), true)) {
            throw new BadRequest('This number is blocked for WhatsApp (opted out, invalid or removed).');
        }

        return [$phone, $contact];
    }

    /** @return list<string> stages this enrollment has entered */
    private function visitedStages(Entity $r): array
    {
        $ids = [];
        foreach ($this->em->getRDBRepository('JourneyRecordLog')
            ->where(['recordId' => $r->getId(), 'toStageId!=' => null])
            ->select(['toStageId'])->find() as $log) {
            $ids[] = (string) $log->get('toStageId');
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function saveState(Entity $r, array $state): void
    {
        $r->set('replyReviewState', (object) $state);
        $this->em->saveEntity($r, [SaveOption::SILENT => true, 'skipJourneyDispatch' => true]);
    }

    /** A $from stage logs a move from it to the record's current stage. */
    private function log(Entity $r, string $action, ?string $actor, array $extra = [], ?string $from = null): void
    {
        $this->em->createEntity('JourneyRecordLog', [
            'recordId' => $r->getId(), 'journeyId' => $r->get('journeyId'), 'tenantId' => $r->get('tenantId'),
            'fromStageId' => $from ?? $r->get('currentStageId'), 'toStageId' => $from ? $r->get('currentStageId') : null,
            'firedBy' => $actor ? 'user' : 'signal', 'actorId' => $actor,
            'eventPayload' => ['action' => $action] + $extra, 'teamsIds' => $r->getLinkMultipleIdList('teams'),
        ], [SaveOption::SILENT => true]);
    }
}
