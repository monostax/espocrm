<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use DateTimeZone;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCatchUp\Classes\DeckRequest;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\Loops;
use Espo\Modules\FeatureCatchUp\Services\ServiceFeed;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * A person's Em dia, read as that person (ActorScope): the same feeds, order, access rules (Ownership, the CRM ACL,
 * Chatwoot's conversation access) and stored verdicts as the deck in the app. A WhatsApp card is a card with a reply to
 * send (customer waiting, promise due, follow-up due, stalled deal with a check-in); found deals and Ganho/Perdido stay
 * in the app. A card is never analysed here: only subjects already judged at their current revision are offered, and
 * Summary answers from the stored verdict (a stored verdict the clock or a closed deal made outdated is analysed again,
 * as when the app's deck reaches it).
 *
 * Each card shown is saved as a WhatsAppDeckCard with the revision it was built from; acting on it later checks the
 * revision again (Actions), so a conversation that moved since is never answered from an old card.
 */
class Deck
{
    public const DRAFT_VERDICTS = ['customer_waiting', 'promise_due', 'follow_up_due', 'next_step_missing'];
    /** The template says "{{1}} está esperando resposta": only true when the customer waits on us. */
    public const TEMPLATE_VERDICTS = ['customer_waiting', 'promise_due'];
    public const LANES = ['deals', 'service'];
    /** A card sent to the person is never sent again within this many days (the user, 2026-11-23). */
    public const REPEAT_WINDOW_DAYS = 4;
    /** Cards waiting at least this long go out first; the newer ones only when none is left (the user, 2026-11-23). */
    public const PREFER_WAITING_HOURS = 24;
    private const PAGES = 3;
    /** Snapshots built per call while looking for a card that is ready. */
    private const TRIES = 6;

    public function __construct(
        private EntityManager $em,
        private ActorScope $actors,
        private Workspaces $workspaces,
        private Log $log,
    ) {}

    /**
     * The next card: first those waiting PREFER_WAITING_HOURS or more (`waitSince`), then the newer ones; within each, Em
     * dia's order (Esperando resposta, then Esperando follow-up; within each, the feeds' sort key). Skips the subjects in
     * the session's skip list and any card sent to the person in the last REPEAT_WINDOW_DAYS days.
     *
     * @param list<string> $verdicts the card verdicts that may be offered
     * @param ?callable(array<string, mixed>): bool $accept an extra filter on the stored card (hot leads)
     * @param ?list<string> $exclude "lane:id" subjects to pass over; null loads `sentRecently`
     * @return array{card: ?Entity, left: int}
     */
    public function next(Entity $subscription, array $verdicts = self::DRAFT_VERDICTS, ?callable $accept = null, ?array $exclude = null): array
    {
        $workspace = $this->workspaces->of($subscription);
        $skip = array_flip([...Sessions::skipped($subscription), ...($exclude ?? $this->sentRecently($subscription))]);
        return $this->actors->run((string) $subscription->get('userId'), function (callable $make) use ($subscription, $workspace, $skip, $verdicts, $accept): array {
            $request = self::request($workspace);
            $feeds = ['deals' => $make(Feed::class), 'service' => $make(ServiceFeed::class)];
            $candidates = [];
            $longWait = time() - self::PREFER_WAITING_HOURS * 3600;
            foreach ($feeds as $lane => $feed) {
                $found = [];
                $cursor = null;
                for ($page = 0; $page < self::PAGES; $page++) {
                    $list = $feed->list($request->withQuery('cursor', $cursor));
                    foreach ($list['items'] as $item) {
                        $verdict = $item['verdict'] ?? null;
                        if (!in_array($verdict, $verdicts, true) || isset($skip["{$lane}:{$item['id']}"])) continue;
                        $found[(string) $item['id']] = ['lane' => $lane, 'id' => (string) $item['id'], 'verdict' => $verdict,
                            'tab' => Loops::BUCKETS[$verdict] === 'us' ? 0 : 1, 'sort' => is_array($item['sort'] ?? null) ? $item['sort'] : [9, 0]];
                    }
                    $cursor = $list['cursor'] ?? null;
                    if ($cursor === null) break;
                }
                $since = $this->waitsSince($lane, array_column($found, 'verdict', 'id'));
                foreach ($found as $id => $candidate) {
                    $at = $since[$id] ?? null;
                    $candidates[] = [...$candidate, 'newer' => $at !== null && strtotime($at . ' UTC') <= $longWait ? 0 : 1];
                }
            }
            usort($candidates, fn (array $a, array $b) => [$a['newer'], $a['tab'], $a['sort']] <=> [$b['newer'], $b['tab'], $b['sort']]);
            $summary = $make(Summary::class);
            foreach (array_slice($candidates, 0, self::TRIES) as $index => $candidate) {
                $card = $this->build($subscription, $workspace, $feeds[$candidate['lane']], $summary, $candidate['lane'], $candidate['id'], $verdicts, $accept);
                if ($card) return ['card' => $card, 'left' => count($candidates) - $index - 1];
            }
            return ['card' => null, 'left' => 0];
        });
    }

    /**
     * "lane:id" of every card sent to the person within the last $days days, from any of their workspaces and any kind
     * of message, failed sends aside.
     *
     * @return list<string>
     */
    public static function sentWithin(EntityManager $em, Workspaces $workspaces, Entity $subscription, int $days = self::REPEAT_WINDOW_DAYS): array
    {
        $since = $workspaces->now($subscription)->modify("-{$days} days")->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $rows = $em->getRDBRepository('WhatsAppNotificationSubscription')->select(['id'])
            ->where(['userId' => $subscription->get('userId'), 'phone' => $subscription->get('phone')])->find();
        $logs = $em->getRDBRepository('WhatsAppNotification')->select(['cardId'])->where([
            'subscriptionId' => [$subscription->getId(), ...array_map(fn (Entity $row) => $row->getId(), iterator_to_array($rows))],
            'direction' => 'out', 'cardId!=' => null, 'createdAt>=' => $since, 'status!=' => 'failed',
        ])->find();
        $cardIds = array_values(array_unique(array_map(fn (Entity $log) => (string) $log->get('cardId'), iterator_to_array($logs))));
        if (!$cardIds) return [];
        $cards = $em->getRDBRepository('WhatsAppDeckCard')->select(['lane', 'subjectId'])->where(['id' => $cardIds])->find();
        return array_values(array_unique(array_map(fn (Entity $card) => $card->get('lane') . ':' . $card->get('subjectId'), iterator_to_array($cards))));
    }

    /** @return list<string> "lane:id" of every card sent to the person in the last REPEAT_WINDOW_DAYS days */
    public function sentRecently(Entity $subscription): array
    {
        return self::sentWithin($this->em, $this->workspaces, $subscription);
    }

    /** One subject again (a reminder, or a card whose conversation moved): its card now, when it still is one. */
    public function subject(Entity $subscription, string $lane, string $id, array $verdicts = self::DRAFT_VERDICTS): ?Entity
    {
        if (!in_array($lane, self::LANES, true)) return null;
        $workspace = $this->workspaces->of($subscription);
        return $this->actors->run((string) $subscription->get('userId'), fn (callable $make): ?Entity => $this->build($subscription, $workspace,
            $make($lane === 'deals' ? Feed::class : ServiceFeed::class), $make(Summary::class), $lane, $id, $verdicts, null));
    }

    /**
     * A card before showing its draft: the same card when its subject hasn't moved, else the subject's card now (a new
     * WhatsAppDeckCard; the old one becomes `stale`), or null when it no longer is one or isn't analysed yet.
     */
    public function refresh(Entity $subscription, Entity $card): ?Entity
    {
        $workspace = $this->workspaces->of($subscription);
        $lane = (string) $card->get('lane');
        $id = (string) $card->get('subjectId');
        $fresh = $this->actors->run((string) $subscription->get('userId'), function (callable $make) use ($subscription, $workspace, $card, $lane, $id): Entity|false|null {
            $feed = $make($lane === 'deals' ? Feed::class : ServiceFeed::class);
            try {
                if ((string) $feed->snapshot($id, self::request($workspace))['revision'] === (string) $card->get('revision')) return false;
            } catch (Throwable) {
                return null;
            }
            return $this->build($subscription, $workspace, $feed, $make(Summary::class), $lane, $id, self::DRAFT_VERDICTS, null);
        });
        if ($fresh === false) return $card;
        $card->set('status', 'stale');
        $this->em->saveEntity($card);
        return $fresh;
    }

    /**
     * What is left in the person's Em dia: every card in Esperando resposta and Esperando follow-up, deals and
     * conversations (the deck's tab totals).
     */
    public function left(Entity $subscription): int
    {
        $workspace = $this->workspaces->of($subscription);
        try {
            return $this->actors->run((string) $subscription->get('userId'), function (callable $make) use ($workspace): int {
                $deals = $make(Feed::class)->count(self::request($workspace));
                $service = $make(ServiceFeed::class)->count((string) $workspace['tenant']->getId(), $workspace['account']);
                return $deals['us'] + $deals['customer'] + $service['us'] + $service['customer'];
            });
        } catch (Throwable) {
            return 0;
        }
    }

    /** The Em dia request a feed reads: the workspace's Chatwoot account and time zone. */
    public static function request(array $workspace, array $body = []): DeckRequest
    {
        return new DeckRequest(['accountId' => (string) $workspace['accountNumber'], 'timeZone' => $workspace['timeZone']], $body);
    }

    /** The analysis locale verdicts are stored under, from the workspace language. */
    public static function locale(array $workspace): string
    {
        return Summary::locale($workspace['language']);
    }

    private function build(Entity $subscription, array $workspace, Feed|ServiceFeed $feed, Summary $summary, string $lane, string $id,
        array $verdicts, ?callable $accept): ?Entity
    {
        $request = self::request($workspace);
        try {
            $snapshot = $feed->snapshot($id, $request);
            $answer = $summary->analyse($snapshot, self::locale($workspace));
        } catch (Throwable $e) {
            $this->log->info("WhatsApp notifications: {$lane} {$id} skipped for subscription {$subscription->getId()}: " . $e->getMessage());
            return null;
        }
        // Still being analysed (queued now, or by settle): the card comes later.
        if (($answer['status'] ?? null) !== 'completed') return null;
        $card = is_array($answer['card'] ?? null) ? $answer['card'] : null;
        $draft = trim((string) ($card['draft'] ?? ''));
        $conversation = $snapshot['conversation'] ?? null;
        if (!in_array($answer['verdict'] ?? null, $verdicts, true) || $draft === '' || !is_array($conversation)) return null;
        if ((int) ($conversation['accountId'] ?? 0) !== $workspace['accountNumber']) return null;
        if ($accept && !$accept($card)) return null;
        $context = trim(implode(' ', array_filter([(string) ($card['whyNow'] ?? ''), (string) ($card['insight'] ?? '')])));
        return $this->em->createEntity('WhatsAppDeckCard', [
            'subscriptionId' => $subscription->getId(), 'lane' => $lane, 'subjectId' => $id,
            'revision' => (string) $snapshot['revision'], 'snapshotToken' => (string) $snapshot['snapshot'],
            'verdict' => $answer['verdict'],
            'contactName' => mb_substr((string) (($snapshot['contactName'] ?? null) ?: ($snapshot['name'] ?? '')), 0, 150),
            'headline' => mb_substr((string) ($card['headline'] ?? ''), 0, 255),
            'context' => $context, 'draft' => $draft,
            'waitSince' => $this->waitSince($lane, $id, (string) $answer['verdict']),
            'conversationAccountId' => (int) $conversation['accountId'], 'conversationId' => (int) $conversation['conversationId'],
            'status' => 'shown',
        ]);
    }

    /** Since when the customer has waited on us (or, for a follow-up or stalled deal, since the conversation went quiet). */
    private function waitSince(string $lane, string $id, string $verdict): ?string
    {
        return $this->waitsSince($lane, [$id => $verdict])[$id] ?? null;
    }

    /**
     * `waitSince` for many subjects of one lane in one query.
     *
     * @param array<string, string> $verdicts subject id => verdict
     * @return array<string, string> subject id => UTC datetime, for those with one
     */
    private function waitsSince(string $lane, array $verdicts): array
    {
        if (!$verdicts) return [];
        $ids = array_map('strval', array_keys($verdicts));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = $lane === 'deals'
            ? "SELECT id, last_customer_at AS customer_at, last_message_at AS last_at FROM catch_up_deal_state WHERE id IN ({$in}) AND deleted = 0"
            : "SELECT id, last_message_received_at AS customer_at, last_activity_at AS last_at FROM chatwoot_conversation WHERE id IN ({$in}) AND deleted = 0";
        $query = $this->em->getPDO()->prepare($sql);
        $query->execute($ids);
        $since = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $waiting = in_array($verdicts[$row['id']] ?? null, self::TEMPLATE_VERDICTS, true);
            $at = $waiting ? ($row['customer_at'] ?: $row['last_at']) : $row['last_at'];
            if (is_string($at) && $at !== '') $since[(string) $row['id']] = $at;
        }
        return $since;
    }
}
