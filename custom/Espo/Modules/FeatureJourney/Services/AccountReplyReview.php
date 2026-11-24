<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\Chatwoot\Tools\PhoneNormalizer;
use Espo\Modules\Chatwoot\Tools\WhatsAppRemovals;
use Espo\Modules\FeatureCredits\Enrichment\ContactPhones;
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
            // A number marked wrong, or a removed contact's number, no longer pauses the company.
            if (isset($state['excludedConversations'][$key]) || in_array($phone, $state['excluded'] ?? [], true) ||
                ($phone && in_array($phone, array_merge(...array_values($state['removedContacts'] ?? [])), true))) {
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

    /**
     * Credits the time since $since (UTC) to the wait at the record's current stage, so a resumed
     * run keeps the wait that was left ({@see PeriodParser::isStageDue}).
     */
    public static function hold(Entity $r, array $s, ?string $since, string $zone): array
    {
        if (($s['heldStage'] ?? null) !== $r->get('currentStageId') ||
            ($s['heldEntered'] ?? null) !== $r->get('enteredStageAt')) {
            $s['heldSeconds'] = $s['heldWeekdaySeconds'] = 0;
        }
        $now = time();
        $start = $since ? strtotime($since . ' UTC') : $now;
        $s['heldSeconds'] = ($s['heldSeconds'] ?? 0) + max(0, $now - $start);
        $s['heldWeekdaySeconds'] = ($s['heldWeekdaySeconds'] ?? 0) + PeriodParser::weekdaySeconds($start, $now, $zone);
        $s['heldStage'] = $r->get('currentStageId');
        $s['heldEntered'] = $r->get('enteredStageAt');

        return $s;
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
     * The company's contacts that still get the cadence: the ones a number can be picked for
     * when resuming, and the ones that can be removed. `wrongNumber` marks the contacts whose
     * number was marked wrong, the usual place for the new one. With edit access, `numbers`
     * lists what can replay the cadence on each contact: its own numbers (`record`) and the
     * numbers enrichment found that no contact of the company has (`enrichment`).
     * `removedContacts` are the readable contacts taken out of this cadence; `pendingContacts`
     * names the contact behind each reply waiting for review.
     *
     * @return array{contacts: list<array{contactId: string, contactName: string, wrongNumber: bool, numbers?: list<array<string, mixed>>}>, removedContacts: list<array{contactId: string, contactName: string}>, pendingContacts: array<string, array{contactId: string, contactName: string}>, canAddNumber: bool}
     */
    public function contacts(
        Entity $record,
        Acl $acl,
        JourneyTargetPeople $people,
        JourneyWhatsAppOutbound $outbound,
        ?ContactPhones $enriched = null,
    ): array {
        $tenant = (string) ($record->get('tenantId') ?? '');
        $target = $record->get('targetType') === 'Account' && $record->get('targetId')
            ? $this->em->getEntityById('Account', (string) $record->get('targetId'))
            : null;
        $canAdd = $acl->checkScope('Contact', 'edit') && $acl->checkField('Contact', 'phoneNumber', 'edit');
        if (!$tenant || !$target || !$acl->check($target, 'read') ||
            !$acl->checkScope('Contact', 'read') || !$acl->checkField('Contact', 'phoneNumber', 'read')) {
            return ['contacts' => [], 'removedContacts' => [], 'pendingContacts' => [], 'canAddNumber' => false];
        }
        $state = self::state($record);
        $excluded = $state['excluded'] ?? [];
        $removed = $state['removedContacts'] ?? [];
        $catchingUp = JourneyCatchUp::active($state) ? ($state['catchUp']['phones'] ?? []) : [];
        $canReadName = $acl->checkField('Contact', 'name', 'read');
        $canReadIdentities = $acl->checkScope('ContactChannelIdentity', 'read');
        $company = [];
        $readable = [];
        foreach ($people->forTarget($target, $tenant) as $person) {
            $phones = $outbound->resolvePhones($person);
            array_push($company, ...$phones);
            if ($person->getEntityType() === 'Contact' && $acl->check($person, 'read') && !$outbound->isOptedOut($person)) {
                $readable[] = [$person, $phones];
            }
        }
        $list = [];
        $gone = [];
        $repliers = [];
        foreach ($readable as [$person, $phones]) {
            $name = $canReadName ? trim((string) ($person->get('name') ?? '')) : '';
            if ($name === '' && $canReadName) {
                $name = trim(((string) ($person->get('firstName') ?? '')) . ' ' . ((string) ($person->get('lastName') ?? '')));
            }
            $id = (string) $person->getId();
            if (isset($removed[$id])) {
                $gone[] = ['contactId' => $id, 'contactName' => $name];
                continue;
            }
            foreach ($state['pending'] ?? [] as $conversation => $reply) {
                if ($reply['phone'] && in_array($reply['phone'], $phones, true)) {
                    $repliers[$conversation] ??= ['contactId' => $id, 'contactName' => $name];
                }
            }
            $item = [
                'contactId' => $id,
                'contactName' => $name,
                'wrongNumber' => array_intersect($phones, $excluded) !== [],
            ];
            if ($canAdd) {
                $item['numbers'] = $this->numbers($person, $phones, $company, $excluded, $catchingUp,
                    $canReadIdentities, $tenant, $outbound, $enriched);
            }
            $list[] = $item;
        }
        usort($list, static fn ($a, $b) => (int) $b['wrongNumber'] <=> (int) $a['wrongNumber']);

        return ['contacts' => $list, 'removedContacts' => $gone, 'pendingContacts' => $repliers,
            'canAddNumber' => $canAdd && $list !== []];
    }

    /**
     * @param list<string> $phones the contact's sendable numbers
     * @param list<string> $company every sendable number of the company's contacts
     * @return list<array<string, mixed>> record numbers first, then enrichment numbers with WhatsApp first
     */
    private function numbers(Entity $person, array $phones, array $company, array $excluded, array $catchingUp,
        bool $canReadIdentities, string $tenant, JourneyWhatsAppOutbound $outbound, ?ContactPhones $enriched): array
    {
        // Without channel identity access, only the contact's phone fields are shown.
        $visible = $canReadIdentities ? $phones : array_intersect($phones, $outbound->resolvePhones($person, null, false));
        $numbers = [];
        foreach (array_diff($visible, $excluded) as $phone) {
            $numbers[$phone] = ['phone' => $phone, 'source' => 'record', 'catchingUp' => isset($catchingUp[$phone])];
        }
        $found = [];
        foreach ($enriched?->list($tenant, (string) $person->getId()) ?? [] as $item) {
            $phone = PhoneNormalizer::normalize($item['value']);
            // A number on another contact already gets the cadence; one marked wrong never does.
            if ($phone && !isset($numbers[$phone]) && !isset($found[$phone]) &&
                !in_array($phone, $company, true) && !in_array($phone, $excluded, true)) {
                $found[$phone] = ['phone' => $phone, 'source' => 'enrichment',
                    'ownership' => $item['ownership'], 'whatsapp' => $item['whatsapp']];
            }
        }
        $rank = static fn (array $n): array => [
            ['registered' => 0, 'not_registered' => 2][$n['whatsapp'] ?? ''] ?? 1,
            ['verified' => 0, 'not_verified' => 2, 'rejected' => 3][$n['ownership'] ?? ''] ?? 1,
        ];
        usort($found, static fn ($a, $b) => $rank($a) <=> $rank($b));

        return [...array_values($numbers), ...$found];
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
            $decision = $input->decision ?? '';
            if ($decision === 'removeContact') {
                // Any time while the cadence runs, not only during a review.
                if (!in_array($r->get('status'), ['Active', 'Processing', 'Paused'], true)) {
                    throw new Conflict('This cadence is no longer running.');
                }
                $this->removeContact($r, $s, (string) ($input->contactId ?? ''), $actor, $acl, $people, $outbound);
                return self::summary($r);
            }
            if ($r->get('status') !== 'Paused' || !$r->get('replyPausedAt') || empty($s['revision']) ||
                $s['revision'] !== ($input->revision ?? null)) {
                throw new Conflict('This review changed. Reopen it before continuing.');
            }
            $j = $this->em->getEntityById('Journey', $r->get('journeyId'));
            $tenant = $r->get('tenantId');
            if (!$tenant || !$j || $j->get('tenantId') !== $tenant || $j->get('status') !== 'Active') {
                throw new BadRequest('The parent journey must be active.');
            }
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
                if (!empty($s['pending'])) {
                    throw new BadRequest('Review every reply before resuming.');
                }
                // `numbers`: [{contactId, phone, source}]. A single `phone`/`contactId` is one number.
                $picks = is_array($input->numbers ?? null) ? $input->numbers : (trim((string) ($input->phone ?? '')) !== ''
                    ? [(object) ['phone' => $input->phone, 'contactId' => $input->contactId ?? '', 'source' => $input->source ?? 'manual']] : []);
                if (count($picks) > 20) {
                    throw new BadRequest('Pick at most 20 numbers at a time.');
                }
                if ($picks !== []) {
                    // The picked numbers start from the Entry stage together; the others wait where they are.
                    $added = [];
                    foreach ($picks as $pick) {
                        $pick = (object) $pick;
                        $raw = trim((string) ($pick->phone ?? ''));
                        if (isset($added[PhoneNormalizer::normalize($raw) ?? ''])) {
                            continue;
                        }
                        [$phone, $contact] = $this->catchUpNumber($r, $s, $raw, (string) ($pick->contactId ?? ''),
                            (string) ($pick->source ?? 'manual'), $tenant, $acl, $people, $outbound);
                        $added[$phone] = (string) $contact->getId();
                    }
                    $s = JourneyCatchUp::start($s, (string) $r->get('currentStageId'), $r->get('enteredStageAt'),
                        $this->visitedStages($r), $added);
                    unset($s['heldSeconds'], $s['heldWeekdaySeconds'], $s['heldStage'], $s['heldEntered']);
                    $r->set(['status' => 'Active', 'replyPausedAt' => null]);
                    $this->saveState($r, $s);
                    $this->log($r, $decision, $actor, ['contactIds' => array_values(array_unique($added)),
                        'numbers' => count($added), 'catchUp' => true]);
                    if (!$executor->restartForCatchUp($id)) {
                        throw new Conflict('The cadence could not restart for the new numbers. Try again.');
                    }
                    return self::summary($this->em->getEntityById('JourneyRecord', $id));
                }
                if (JourneyCatchUp::abandoned($s)) {
                    // Every added number was wrong: the others continue from the stage they waited at.
                    $from = $r->get('currentStageId');
                    $s = $this->endCatchUp($r, $s);
                    $r->set(['status' => 'Active', 'replyPausedAt' => null]);
                    $this->saveState($r, $s);
                    $this->log($r, $decision, $actor, ['catchUpEnded' => true], $from);
                    return self::summary($r);
                }
                // Everyone except the numbers marked wrong continues, with the wait that was left;
                // a pause made by hand before the reply counts as held too.
                $since = min(array_filter([$r->get('replyPausedAt'), $s['pausedAt'] ?? null]));
                $s = self::hold($r, $s, $since, $j->get('timeZone') ?: 'UTC');
                unset($s['pausedAt']);
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
     * Takes one of the company's contacts out of this cadence: no more messages to them on any
     * channel ({@see ActionContext::recipients}), their replies no longer pause it, and replies of
     * theirs waiting for review are dropped. The others continue. Kept as
     * `removedContacts: {contactId: [phone]}`, with the phones they had when removed.
     */
    private function removeContact(Entity $r, array $s, string $contactId, string $actor, Acl $acl,
        JourneyTargetPeople $people, JourneyWhatsAppOutbound $outbound): void
    {
        $target = $r->get('targetType') === 'Account' && $r->get('targetId')
            ? $this->em->getEntityById('Account', (string) $r->get('targetId')) : null;
        if (!$target || !$acl->check($target, 'read')) {
            throw new Forbidden();
        }
        $contact = null;
        foreach ($people->forTarget($target, (string) $r->get('tenantId')) as $person) {
            if ($person->getEntityType() === 'Contact' && $person->getId() === $contactId) {
                $contact = $person;
            }
        }
        if (!$contact || !$acl->check($contact, 'read')) {
            throw new BadRequest('Pick one of the company\'s contacts.');
        }
        if (isset($s['removedContacts'][$contactId])) {
            return;
        }
        $phones = $outbound->resolvePhones($contact);
        $s['removedContacts'][$contactId] = $phones;
        foreach ($s['pending'] ?? [] as $conversation => $item) {
            if ($item['phone'] && in_array($item['phone'], $phones, true)) {
                $s['excludedConversations'][$conversation] = true;
                unset($s['pending'][$conversation]);
            }
        }
        foreach (JourneyCatchUp::active($s) ? $s['catchUp']['phones'] ?? [] : [] as $phone => $item) {
            if (($item['contactId'] ?? '') === $contactId) {
                $s = JourneyCatchUp::drop($s, (string) $phone);
            }
        }
        // Their numbers were the only ones catching up: the others stop waiting. A paused run does this on resume.
        $from = null;
        if (JourneyCatchUp::abandoned($s) && $r->get('status') === 'Active') {
            $from = $r->get('currentStageId');
            $s = $this->endCatchUp($r, $s);
        }
        $s['revision'] = bin2hex(random_bytes(12));
        $this->saveState($r, $s);
        $this->log($r, 'removeContact', $actor, ['contactId' => $contactId] + ($from ? ['catchUpEnded' => true] : []), $from);
    }

    /** Nobody is left to catch up: back to the stage the others waited at, with its original entry time. */
    private function endCatchUp(Entity $r, array $s): array
    {
        $until = (string) $s['catchUp']['until'];
        $entered = $s['catchUp']['untilEnteredAt'] ?? null;
        unset($s['catchUp'], $s['heldSeconds'], $s['heldWeekdaySeconds'], $s['heldStage'], $s['heldEntered']);
        $r->set(['currentStageId' => $until, 'enteredStageAt' => $entered ?: gmdate('Y-m-d H:i:s')]);

        return $s;
    }

    /**
     * The number that replays the cadence from the Entry stage, on one of the company's contacts.
     * - `record`: a number already on that contact. Nothing is saved; a number that already got
     *   the earlier stages gets them again.
     * - `enrichment`: a number enrichment found, saved as a WhatsApp channel identity.
     * - `manual`: a typed mobile number, saved as a phone number.
     * Enrichment and manual numbers must be new to the company: one already on a contact gets the cadence.
     *
     * @return array{0: string, 1: Entity}
     */
    private function catchUpNumber(Entity $r, array $s, string $raw, string $contactId, string $source, string $tenant,
        Acl $acl, JourneyTargetPeople $people, JourneyWhatsAppOutbound $outbound): array
    {
        if (!in_array($source, ['record', 'enrichment', 'manual'], true)) {
            throw new BadRequest('Unknown number source.');
        }
        $phone = PhoneNormalizer::normalize(trim($raw));
        if (!$phone || ($source === 'manual' && !$outbound->isMobile($phone))) {
            throw new BadRequest('Enter a mobile number with area code.');
        }
        if (in_array($phone, $s['excluded'] ?? [], true)) {
            throw new BadRequest('This number was marked wrong for this company.');
        }
        if (JourneyCatchUp::active($s) && isset($s['catchUp']['phones'][$phone])) {
            throw new BadRequest('This number is already getting the cadence from the start.');
        }
        $target = $this->em->getEntityById('Account', (string) $r->get('targetId'));
        if (!$target || !$acl->check($target, 'read')) {
            throw new Forbidden();
        }
        $contact = null;
        $onContact = false;
        // Every contact of the company counts, readable or not: the cadence reaches them all.
        foreach ($people->forTarget($target, $tenant) as $person) {
            $own = in_array($phone, $outbound->resolvePhones($person), true);
            if ($own && $source !== 'record') {
                throw new BadRequest('This number is already on one of the company\'s contacts, so it already gets the cadence.');
            }
            if ($person->getEntityType() === 'Contact' && $person->getId() === $contactId) {
                $contact = $person;
                $onContact = $own;
            }
        }
        if (!$contact || !$acl->check($contact, 'read')) {
            throw new BadRequest('Pick one of the company\'s contacts for the new number.');
        }
        if (isset($s['removedContacts'][$contact->getId()])) {
            throw new BadRequest('This contact was removed from this cadence.');
        }
        if ($outbound->isOptedOut($contact)) {
            throw new BadRequest('This contact opted out of WhatsApp.');
        }
        if ($source === 'record') {
            if (!$onContact) {
                throw new BadRequest('This number is not on this contact, or it is blocked for WhatsApp.');
            }
            return [$phone, $contact];
        }
        if (!$acl->check($contact, 'edit') || !$acl->checkField('Contact', 'phoneNumber', 'edit')) {
            throw new Forbidden('You can\'t edit this contact\'s phone numbers.');
        }
        $contact = $this->em->getEntityById('Contact', $contact->getId()) ?? $contact;
        if ($source === 'enrichment') {
            // Picking it is an explicit addition, so a WhatsApp number removed from the contact before comes back.
            (new WhatsAppRemovals($this->em))->allowReadded($tenant, $contact->getId(), $phone);
            $contact->set('whatsappNumber', $phone);
        } else {
            /** @var \Espo\Repositories\PhoneNumber $repo */
            $repo = $this->em->getRepository(\Espo\Entities\PhoneNumber::ENTITY_TYPE);
            $data = $repo->getPhoneNumberData($contact);
            $data[] = (object) ['phoneNumber' => $phone, 'type' => 'Mobile', 'primary' => $data === [],
                'optOut' => false, 'invalid' => false];
            $contact->set('phoneNumberData', $data);
        }
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
