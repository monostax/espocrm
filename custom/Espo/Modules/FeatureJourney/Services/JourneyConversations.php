<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Which enrollment is messaging a conversation (`ChatwootConversation.journeyId` / `journeyRecordId`).
 *
 * JourneyWhatsAppOutbound stamps the conversation row at send time, but a send usually opens a conversation the CRM
 * copies in a minute later, so the row did not exist yet (144 of 166 conversations of the Prospecção Ativa cadence on
 * 2026-11-23). The ChatwootConversation hook StampJourneyRecord stamps a row when it is copied in, from the enrollment's
 * conversation trail, and backfill() (rebuild StampJourneyConversations) stamps rows copied in before that.
 *
 * Em dia leaves out conversations whose enrollment is still running or paused (FeatureCatchUp
 * `ServiceFeed::NOT_IN_JOURNEY_SQL`, decided 2026-11-23).
 */
class JourneyConversations
{
    /** Enrollments still messaging their conversations; same set as the reply tracker's. */
    public const LIVE = ['Active', 'Processing', 'Paused'];
    private const CHUNK = 500;

    public function __construct(private EntityManager $entityManager) {}

    /** The newest live enrollment that sent to conversation `$displayId` of the CRM's ChatwootAccount `$espoAccountId`. */
    public function liveRecord(string $espoAccountId, int $displayId): ?Entity
    {
        if ($espoAccountId === '' || $displayId < 1) {
            return null;
        }

        return $this->entityManager
            ->getRDBRepository('JourneyRecord')
            ->where([
                'status' => self::LIVE,
                'OR' => [
                    [
                        'whatsAppChatwootAccountId' => $espoAccountId,
                        'whatsAppChatwootConversationId' => (string) $displayId,
                    ],
                    [
                        'chatwootConversationTrail*' => '%"' .
                            JourneyWhatsAppOutbound::trailKey($espoAccountId, (string) $displayId) . '"%',
                    ],
                ],
            ])
            ->order('modifiedAt', 'DESC')
            ->findOne();
    }

    /**
     * Stamp the existing conversation rows of live enrollments whose stamp is empty or names an enrollment that is no
     * longer live. When several live enrollments sent to one conversation, the newest wins. A row stamped with a live
     * enrollment is left alone, so a second run writes nothing.
     *
     * @return int conversation rows stamped
     */
    public function backfill(): int
    {
        $records = $this->entityManager
            ->getRDBRepository('JourneyRecord')
            ->select([
                'id',
                'journeyId',
                'chatwootConversationTrail',
                'whatsAppChatwootConversationId',
                'whatsAppChatwootAccountId',
            ])
            ->where(['status' => self::LIVE])
            ->order('modifiedAt', 'DESC')
            ->find();

        $live = [];
        /** @var array<string, array<int, array{string, string}>> $owners CRM account id => display id => [record, journey] */
        $owners = [];

        foreach ($records as $record) {
            $live[$record->getId()] = true;

            foreach (self::keys($record) as [$accountId, $displayId]) {
                $owners[$accountId][$displayId] ??= [$record->getId(), (string) ($record->get('journeyId') ?? '')];
            }
        }

        $stamped = 0;

        foreach ($owners as $accountId => $byDisplayId) {
            foreach (array_chunk(array_keys($byDisplayId), self::CHUNK) as $displayIds) {
                $conversations = $this->entityManager
                    ->getRDBRepository('ChatwootConversation')
                    ->select(['id', 'chatwootConversationId', 'journeyRecordId'])
                    ->where(['chatwootAccountId' => (string) $accountId, 'chatwootConversationId' => $displayIds])
                    ->find();

                foreach ($conversations as $conversation) {
                    $current = (string) ($conversation->get('journeyRecordId') ?? '');

                    if ($current !== '' && isset($live[$current])) {
                        continue;
                    }

                    $owner = $byDisplayId[(int) $conversation->get('chatwootConversationId')] ?? null;

                    if (!$owner) {
                        continue;
                    }

                    $this->entityManager->getQueryExecutor()->execute(
                        $this->entityManager->getQueryBuilder()
                            ->update()
                            ->in('ChatwootConversation')
                            ->set(['journeyRecordId' => $owner[0], 'journeyId' => $owner[1] !== '' ? $owner[1] : null])
                            ->where(['id' => $conversation->getId()])
                            ->build()
                    );
                    $stamped++;
                }
            }
        }

        return $stamped;
    }

    /**
     * The conversations an enrollment sent to: its trail plus the last one it stamped (enrollments from before the trail).
     *
     * @return list<array{string, int}> [CRM account id, display id]
     */
    private static function keys(Entity $record): array
    {
        $trail = $record->get('chatwootConversationTrail');
        $keys = is_array($trail) ? array_values(array_filter($trail, 'is_string')) : [];
        $account = (string) ($record->get('whatsAppChatwootAccountId') ?? '');
        $conversation = (string) ($record->get('whatsAppChatwootConversationId') ?? '');

        if ($account !== '' && $conversation !== '') {
            $keys[] = JourneyWhatsAppOutbound::trailKey($account, $conversation);
        }

        $pairs = [];

        foreach ($keys as $key) {
            $at = strrpos($key, ':');

            if ($at === false) {
                continue;
            }

            $displayId = substr($key, $at + 1);

            if ($at > 0 && ctype_digit($displayId) && (int) $displayId > 0) {
                $pairs[] = [substr($key, 0, $at), (int) $displayId];
            }
        }

        return $pairs;
    }
}
