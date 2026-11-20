<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
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
            $pending[$conversation] = ['phone' => $item['phone'] ? '•••• ' . substr($item['phone'], -4) : null];
        }
        return ['status' => $record->get('status'), 'replyPausedAt' => $record->get('replyPausedAt'),
            'revision' => $s['revision'] ?? '', 'pending' => $pending];
    }

    public function decide(string $id, object $input, string $actor, Acl $acl, JourneyTargetPeople $people,
        JourneyWhatsAppOutbound $outbound, TransitionExecutor $executor): array
    {
        return $this->em->getTransactionManager()->run(function () use ($id, $input, $actor, $acl, $people, $outbound, $executor) {
            $r = $this->em->getRDBRepository('JourneyRecord')->where(['id' => $id])->forUpdate()->findOne();
            if (!$r || !$acl->check($r, 'edit') || !$acl->checkField('JourneyRecord', 'status', 'edit')) {
                throw new \Espo\Core\Exceptions\Forbidden();
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
                $s['revision'] = bin2hex(random_bytes(12));
                $this->saveState($r, $s);
                $this->log($r, $decision, $actor, ['conversation' => $key]);
            } elseif ($decision === 'resume') {
                if (!empty($s['pending']) || empty($s['excluded'])) {
                    throw new BadRequest('Review every reply before resuming.');
                }
                $target = $this->em->getEntityById('Account', $r->get('targetId'));
                $phone = PhoneNormalizer::normalize((string) ($input->replacement ?? ''));
                $contact = null;
                if ($phone && !in_array($phone, $s['excluded'], true) && $target &&
                    $acl->check($target, 'read') && $acl->checkField('Contact', 'phoneNumber', 'read')) {
                    foreach ($people->forTarget($target, $tenant) as $person) {
                        if ($acl->check($person, 'read') && !$outbound->isOptedOut($person) &&
                            in_array($phone, $outbound->resolvePhones($person), true)) {
                            if ($contact) {
                                throw new BadRequest('More than one linked contact uses this number.');
                            }
                            $contact = $person;
                        }
                    }
                }
                if (!$contact) {
                    throw new BadRequest('Confirm an eligible alternative number on a linked contact.');
                }
                $s['phone'] = $phone;
                $s['contactId'] = $contact->getId();
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
                $this->log($r, $decision, $actor, ['contactId' => $contact->getId()]);
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

    private function saveState(Entity $r, array $state): void
    {
        $r->set('replyReviewState', (object) $state);
        $this->em->saveEntity($r, [SaveOption::SILENT => true, 'skipJourneyDispatch' => true]);
    }

    private function log(Entity $r, string $action, ?string $actor, array $extra = []): void
    {
        $this->em->createEntity('JourneyRecordLog', [
            'recordId' => $r->getId(), 'journeyId' => $r->get('journeyId'), 'tenantId' => $r->get('tenantId'),
            'fromStageId' => $r->get('currentStageId'), 'firedBy' => $actor ? 'user' : 'signal', 'actorId' => $actor,
            'eventPayload' => ['action' => $action] + $extra, 'teamsIds' => $r->getLinkMultipleIdList('teams'),
        ], [SaveOption::SILENT => true]);
    }
}
