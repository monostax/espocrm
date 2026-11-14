<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureCatchUp\Services\Agenda;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\ServiceFeed;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Messages nobody asked for, so they stay few (Meta blocked 247 per-event alerts from this number in Jul–Aug 2026 with
 * 131049):
 *
 * - the morning push, once a day at the person's time (workspace time zone), skipped when there is nothing: the digest
 *   template when `digestTemplate` is configured (its "Ver agora" starts the deck), else the first card;
 * - reminders, when a card the person put off with "Me lembre mais tarde" comes due, if it is still a card;
 * - hot leads: a customer with buying intent waiting `hotLeadMinutes` on us while the person is away from Chatwoot,
 *   inside their alert hours, at most `hotLeadDailyCap` a day for the person across all their workspaces (one number)
 *   and once per customer message.
 *
 * Each workspace sends its own (its morning card at its time, its hot leads), only while the person is still in it.
 *
 * Each has a send key, so a retried job never sends twice.
 */
class Pushes
{
    /** A morning push missed by more than this (the job was down) waits for tomorrow. */
    private const LATE_SECONDS = 3 * 3600;

    public function __construct(
        private EntityManager $em,
        private Settings $settings,
        private Workspaces $workspaces,
        private Taps $taps,
        private Deck $deck,
        private Cards $cards,
        private Avisos $avisos,
        private Tokens $tokens,
        private ActorScope $actors,
        private ChatwootApiClient $api,
        private Log $log,
    ) {}

    public function morning(Entity $subscription): void
    {
        if (!$this->workspaces->live($subscription)) return;
        $workspace = $this->workspaces->of($subscription);
        if ($workspace['frozen'] || !$subscription->get('morningEnabled')) return;
        $now = $this->workspaces->now($subscription);
        $today = $now->format('Y-m-d');
        if ($subscription->get('lastMorningOn') === $today) return;
        $due = new DateTimeImmutable($today . ' ' . $subscription->get('morningTime'), $now->getTimezone());
        if ($now < $due || $now->getTimestamp() > $due->getTimestamp() + self::LATE_SECONDS) return;
        // Claimed before sending: one morning push a day, even if this one fails.
        $subscription->set('lastMorningOn', $today);
        $this->em->saveEntity($subscription, ['silent' => true]);
        $key = "morning:{$subscription->getId()}:{$today}";
        $this->settings->digestTemplate() ? $this->digest($subscription, $key) : $this->taps->next($subscription, 'card', $key, false);
    }

    /** The day's totals as the person sees them, with "Ver agora". Nothing when all are zero. */
    public function digest(Entity $subscription, string $key): void
    {
        $workspace = $this->workspaces->of($subscription);
        $counts = $this->actors->run((string) $subscription->get('userId'), function (callable $make) use ($workspace): array {
            $request = Deck::request($workspace);
            $deals = $make(Feed::class)->count($request);
            $service = $make(ServiceFeed::class)->count((string) $workspace['tenant']->getId(), $workspace['account']);
            $today = (new DateTimeImmutable('now', new DateTimeZone($workspace['timeZone'])))->format('Y-m-d');
            $agenda = array_filter($make(Agenda::class)->list($request)['items'], fn (array $item) => str_starts_with((string) ($item['dueAt'] ?? ''), $today));
            return ['waiting' => $deals['us'] + $service['us'], 'found' => $deals['found'] + $service['found'], 'today' => count($agenda)];
        });
        if (!array_sum($counts)) return;
        $user = $this->em->getEntityById('User', (string) $subscription->get('userId'));
        $name = (string) ($user?->get('firstName') ?: $user?->get('name') ?: '');
        $language = $workspace['language'];
        $text = Copy::t($language, 'digest', ['name' => $name, ...$counts]);
        if (Avisos::windowOpen($subscription)) {
            $this->avisos->text($subscription, 'digest', $text . "\n\n" . Copy::t($language, 'digest.open', ['link' => $this->workspaces->deckLink($subscription)]), null, $key);
            return;
        }
        $this->avisos->template($subscription, 'digest', (string) $this->settings->digestTemplate(), $text,
            [Avisos::parameter($name ?: '—', 60), (string) $counts['waiting'], (string) $counts['found'], (string) $counts['today']], [], null, $key);
    }

    /** A card put off with "Me lembre mais tarde" whose time has come: sent again when it still is a card. */
    public function remind(Entity $card): void
    {
        $card->set('remindedAt', gmdate('Y-m-d H:i:s'));
        $this->em->saveEntity($card);
        $subscription = $this->em->getEntityById('WhatsAppNotificationSubscription', (string) $card->get('subscriptionId'));
        if (!$subscription || $subscription->get('status') !== 'active' || !$this->workspaces->live($subscription) ||
            $this->workspaces->of($subscription)['frozen']) return;
        $verdicts = Avisos::windowOpen($subscription) ? Deck::DRAFT_VERDICTS : Deck::TEMPLATE_VERDICTS;
        $fresh = $this->deck->subject($subscription, (string) $card->get('lane'), (string) $card->get('subjectId'), $verdicts);
        if ($fresh) $this->cards->show($subscription, $fresh, 'reminder', "reminder:{$card->getId()}");
    }

    /**
     * Hot leads for one person: Em dia's own judgment picks them (a reply card on which the customer showed buying
     * intent carries the deal it found, prompt 15); code only checks the times, the cap and whether the person is away.
     */
    public function hotLeads(Entity $subscription): void
    {
        if (!$this->workspaces->live($subscription)) return;
        $workspace = $this->workspaces->of($subscription);
        if ($workspace['frozen'] || !$subscription->get('hotLeadsEnabled')) return;
        $now = $this->workspaces->now($subscription);
        if (!self::inside($now->format('H:i'), (string) $subscription->get('alertStart'), (string) $subscription->get('alertEnd'))) return;
        $cap = $this->settings->hotLeadDailyCap();
        $midnight = $now->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        // The cap is the person's (one number), whichever workspaces the alerts came from.
        $rows = $this->em->getRDBRepository('WhatsAppNotificationSubscription')->select(['id'])
            ->where(['userId' => $subscription->get('userId'), 'phone' => $subscription->get('phone')])->find();
        $sent = $this->em->getRDBRepository('WhatsAppNotification')->where([
            'subscriptionId' => [$subscription->getId(), ...array_map(fn (Entity $row) => $row->getId(), iterator_to_array($rows))],
            'kind' => 'hot_lead', 'createdAt>=' => $midnight, 'status!=' => 'failed',
        ])->count();
        if ($sent >= $cap || $this->online($subscription)) return;
        // One alert per customer a day; the send key below also keeps it to one per customer message.
        $alerted = [];
        $logs = $this->em->getRDBRepository('WhatsAppNotification')->where([
            'subscriptionId' => $subscription->getId(), 'kind' => 'hot_lead', 'createdAt>=' => $midnight,
        ])->find();
        foreach ($logs as $log) {
            $card = $log->get('cardId') ? $this->em->getEntityById('WhatsAppDeckCard', (string) $log->get('cardId')) : null;
            if ($card) $alerted[] = $card->get('lane') . ':' . $card->get('subjectId');
        }
        // Waiting on us with buying intent: the reply card carries the deal Em dia found (prompt 15).
        $card = $this->deck->next($subscription, ['customer_waiting'], fn (array $stored): bool => is_array($stored['proposedDeal'] ?? null), $alerted)['card'];
        if (!$card) return;
        // The longest wait comes first in the deck, so a first card under the minimum means none is due yet.
        $waited = $card->get('waitSince') ? time() - (int) strtotime($card->get('waitSince') . ' UTC') : 0;
        $key = "hot:{$subscription->getId()}:{$card->get('lane')}:{$card->get('subjectId')}:{$card->get('waitSince')}";
        if ($waited < $this->settings->hotLeadMinutes() * 60 || !$this->cards->show($subscription, $card, 'hot_lead', $key)) {
            $card->set('status', 'skipped');
            $this->em->saveEntity($card);
        }
    }

    /** Whether "08:30" is inside [start, end); an end before the start spans midnight. */
    public static function inside(string $time, string $start, string $end): bool
    {
        return $start <= $end ? $time >= $start && $time < $end : $time >= $start || $time < $end;
    }

    /** Whether the person shows as online in Chatwoot (then the app tells them already). Unknown counts as away. */
    private function online(Entity $subscription): bool
    {
        $workspace = $this->workspaces->of($subscription);
        $viewer = $this->em->getRDBRepository('ChatwootUser')->where([
            'assignedUserId' => $subscription->get('userId'), 'platformId' => $workspace['account']->get('platformId'),
        ])->findOne();
        $platform = $workspace['platform'];
        $apiKey = (string) $workspace['account']->get('apiKey');
        if (!$viewer || !$platform?->get('backendUrl') || $apiKey === '') return false;
        try {
            $response = $this->api->accountRequest((string) $platform->get('backendUrl'), $apiKey, 'GET', "/api/v1/accounts/{$workspace['accountNumber']}/agents");
        } catch (Throwable) {
            return false;
        }
        foreach (is_array($response['body']) ? $response['body'] : [] as $agent) {
            if ((int) ($agent['id'] ?? 0) === (int) $viewer->get('chatwootUserId')) return ($agent['availability_status'] ?? '') === 'online';
        }
        return false;
    }
}
