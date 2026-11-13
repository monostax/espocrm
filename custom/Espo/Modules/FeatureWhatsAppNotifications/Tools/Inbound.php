<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureSelfService\Tools\Signup\Signups;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Events of the Avisos inbox, from Chatwoot's account webhook (Chatwoot\Controllers\DeliveryWebhook, only when its
 * signature checked out):
 *
 * - `message_created`, incoming: a tap (a payload we signed, from the number of the subscription it names), a code
 *   (signup or Settings), a stop word, the reply after "Editar", "em dia"/"próximo" (the next card), or anything else
 *   from a subscriber, which goes to their AI (Assistant: their main AI chat, answered back here). With one number in
 *   several workspaces the text goes to the workspace of our last message to them (within 30 minutes), else the one
 *   whose AI they last talked to, or waits until they choose one ("trocar" asks again). Every incoming message opens
 *   Meta's 24h window for that number. Messages from numbers with no subscription stay in the inbox for the Monostax
 *   team.
 * - `message_updated`: Meta's status for one of our messages (sent, delivered, read, failed) and whether Meta billed
 *   it, onto the delivery log; a billed delivery is charged in credits (Charges).
 */
class Inbound
{
    private const STOP_WORDS = ['parar', 'sair', 'stop', 'parar avisos', 'cancelar avisos'];
    /** What the Avisos copy tells people to type for their cards ("Escreva *em dia*…"). */
    private const DECK_WORDS = ['em dia', 'emdia', 'proximo', 'cards', 'ver cards', 'next'];
    private const SWITCH_WORDS = ['trocar', 'trocar espaco', 'mudar espaco', 'trocar de espaco', 'switch', 'switch workspace'];
    private const STATUS_ORDER = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];
    /** A reply within this long of our last message to the person belongs to that message's workspace. */
    public const RECENT_SECONDS = 1800;

    public function __construct(
        private EntityManager $em,
        private Avisos $avisos,
        private Tokens $tokens,
        private PhoneCodes $codes,
        private Subscriptions $subscriptions,
        private Taps $taps,
        private Workspaces $workspaces,
        private Signups $signups,
        private Charges $charges,
        private Assistant $assistant,
        private Log $log,
    ) {}

    /** Whether the event was the Avisos inbox's (then nothing else should handle it). */
    public function handle(object $data, string $espoAccountId): bool
    {
        if (!$this->avisos->isAvisos($espoAccountId, (int) ($data->inbox->id ?? 0))) return false;
        // Chatwoot gives up on a webhook after 5 s and doesn't retry it; finding the next card can take longer.
        ignore_user_abort(true);
        set_time_limit(180);
        try {
            if (($data->event ?? '') === 'message_updated') {
                $this->status($data);
            } elseif (($data->event ?? '') === 'message_created' && ($data->message_type ?? '') === 'incoming') {
                $this->incoming($data);
            }
        } catch (Throwable $e) {
            $this->log->error('WhatsApp notifications: inbound event failed: ' . $e->getMessage());
        }
        return true;
    }

    private function incoming(object $data): void
    {
        $messageId = (int) ($data->id ?? 0);
        $phone = self::phone((string) ($data->sender->phone_number ?? ''));
        if ($messageId <= 0 || $phone === null) return;
        // Once per message: Chatwoot may deliver a webhook twice.
        try {
            $this->em->createEntity('WhatsAppNotification', [
                'direction' => 'in', 'kind' => 'inbound', 'sendKey' => "in:{$messageId}", 'phone' => $phone, 'chatwootMessageId' => $messageId,
            ]);
        } catch (Throwable) {
            return;
        }
        $conversationId = (int) ($data->conversation->id ?? 0);
        $contactId = (int) ($data->sender->id ?? 0);
        $attributes = $data->content_attributes ?? null;
        if (is_string($attributes)) $attributes = json_decode($attributes);
        $payload = is_object($attributes) && is_string($attributes->reply_payload ?? null) ? $attributes->reply_payload : null;
        $text = trim((string) ($data->content ?? ''));

        $subscriptions = $this->subscriptions->activeForPhone($phone);
        foreach ($subscriptions as $subscription) {
            $subscription->set(['lastInboundAt' => gmdate('Y-m-d H:i:s'), 'avisosConversationId' => $conversationId ?: null,
                'avisosContactId' => $contactId ?: null]);
            $this->em->saveEntity($subscription, ['silent' => true]);
        }

        $token = $this->tokens->parse($payload);
        if ($token) {
            $this->tap($token, $phone);
            return;
        }
        $code = PhoneCodes::extract($text);
        if ($code !== null) {
            $this->code($code, $phone, $contactId, $conversationId, PhoneCodes::meant($text));
            return;
        }
        if (in_array(mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N} ]+/u', '', $text))), self::STOP_WORDS, true)) {
            $this->subscriptions->stopPhone($phone, 'keyword');
            return;
        }
        if (!$subscriptions) return;
        foreach ($subscriptions as $subscription) {
            $editing = Sessions::editing($subscription);
            $card = $editing ? $this->em->getEntityById('WhatsAppDeckCard', $editing) : null;
            if ($card && $card->get('subscriptionId') === $subscription->getId()) {
                $this->taps->edited($subscription, $card, $text);
                return;
            }
        }
        $command = self::command($text);
        $subscription = $subscriptions[0];
        if (count($subscriptions) > 1) {
            // What we sent last (a card, a question, an answer) explains the text; else the AI they last talked to.
            $active = $command === 'switch' ? null : ($this->lastMessaged($subscriptions) ?? self::latestAi($subscriptions));
            if (!$active) {
                // The text waits for the choice; a deck word or "trocar" just asks which workspace.
                foreach ($subscriptions as $each) {
                    Sessions::setPending($each, $command === null && $text !== '' ? $text : null, $messageId);
                    $this->em->saveEntity($each, ['silent' => true]);
                }
                $this->choose($subscriptions, $command === 'deck' ? 'choose' : 'choose.ai');
                return;
            }
            $subscription = $active;
        }
        if ($command === 'deck') {
            $this->taps->next($subscription);
            return;
        }
        if ($text === '') {
            $this->avisos->text($subscription, 'assistant', Copy::t($this->workspaces->of($subscription)['language'], 'ai.textOnly'));
            return;
        }
        $this->assistant->ask($subscription, $text, $messageId);
    }

    /** "deck", "switch" or null (a request for the AI): only the exact words the Avisos copy teaches. */
    public static function command(string $text): ?string
    {
        $plain = strtr(mb_strtolower($text), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        $plain = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N} ]+/u', '', $plain)));
        if (in_array($plain, self::DECK_WORDS, true)) return 'deck';
        if (in_array($plain, self::SWITCH_WORDS, true)) return 'switch';
        return null;
    }

    /** @param list<Entity> $subscriptions the one whose workspace sent the person our latest message, in the last 30 minutes */
    private function lastMessaged(array $subscriptions): ?Entity
    {
        $byId = [];
        foreach ($subscriptions as $subscription) $byId[$subscription->getId()] = $subscription;
        $last = $this->em->getRDBRepository('WhatsAppNotification')->where([
            'subscriptionId' => array_keys($byId), 'direction' => 'out', 'status!=' => 'failed',
            'createdAt>=' => gmdate('Y-m-d H:i:s', time() - self::RECENT_SECONDS),
        ])->order('createdAt', 'DESC')->findOne();
        return $last ? ($byId[(string) $last->get('subscriptionId')] ?? null) : null;
    }

    /** @param list<Entity> $subscriptions */
    private static function latestAi(array $subscriptions): ?Entity
    {
        $latest = null;
        foreach ($subscriptions as $subscription) {
            if (Sessions::aiAt($subscription) > ($latest ? Sessions::aiAt($latest) : 0)) $latest = $subscription;
        }
        return $latest;
    }

    /** @param array{kind: string, id: string, action: string} $token */
    private function tap(array $token, string $phone): void
    {
        $card = $token['kind'] === 'c' ? $this->em->getEntityById('WhatsAppDeckCard', $token['id']) : null;
        $subscriptionId = $token['kind'] === 's' ? $token['id'] : (string) $card?->get('subscriptionId');
        $subscription = $subscriptionId !== '' ? $this->em->getEntityById('WhatsAppNotificationSubscription', $subscriptionId) : null;
        // Only the verified number of an active subscription acts.
        if (!$subscription || $subscription->get('status') !== 'active' || $subscription->get('phone') !== $phone) return;
        if ($token['kind'] === 'c' && !$card) return;
        $this->markTapped($subscription, $card);
        // A workspace chosen for a text that was waiting: that text goes to this workspace's AI.
        $pending = $token['kind'] === 's' && $token['action'] === 'w' ? Sessions::pending($subscription) : null;
        foreach ($token['action'] === 'w' ? $this->subscriptions->activeForPhone($phone) : [] as $each) {
            Sessions::setPending($each, null);
            $this->em->saveEntity($each, ['silent' => true]);
        }
        if ($pending) {
            $this->assistant->ask($this->em->getEntityById('WhatsAppNotificationSubscription', $subscription->getId()), $pending['text'],
                $pending['messageId']);
            return;
        }
        $token['kind'] === 'c' ? $this->taps->cardTap($subscription, $card, $token) : $this->taps->subscriptionTap($subscription, $token);
    }

    private function code(string $code, string $phone, int $contactId, int $conversationId, bool $meant): void
    {
        $row = $this->codes->redeem($code, $phone);
        if (!$row) {
            if ($meant && $conversationId > 0) $this->avisos->reply($conversationId, $phone, 'code_reply', Copy::t('pt_BR', 'code.unknown'));
            return;
        }
        if ($row->get('purpose') === PhoneCodes::SIGNUP) {
            $result = $this->signups->phoneVerified((string) $row->get('signupId'), $phone);
            if ($conversationId > 0) {
                $this->avisos->reply($conversationId, $phone, 'code_reply', Copy::t('pt_BR', $result === Signups::PHONE_TAKEN ? 'signup.taken' : 'signup.ok'));
            }
            return;
        }
        $subscription = $this->subscriptions->activate((string) $row->get('userId'), (string) $row->get('chatwootAccountId'), $phone, 'settings',
            $contactId ?: null, $conversationId ?: null);
        $this->welcome($subscription);
    }

    public function welcome(Entity $subscription): void
    {
        $workspace = $this->workspaces->of($subscription);
        $user = $this->em->getEntityById('User', (string) $subscription->get('userId'));
        $vars = ['name' => (string) ($user?->get('firstName') ?: $user?->get('name') ?: ''), 'workspace' => $workspace['name'],
            'time' => (string) $subscription->get('morningTime')];
        $text = Copy::t($workspace['language'], $subscription->get('morningEnabled') ? 'welcome.morning' : 'welcome', $vars);
        $this->avisos->buttons($subscription, 'welcome', $text, [
            [Copy::t($workspace['language'], 'btn.see'), $this->tokens->subscription($subscription->getId(), 'n')],
        ]);
    }

    /**
     * The AI's switch_workspace, for a person who asked from WhatsApp for another of their workspaces: with one other,
     * their message goes straight to its AI; with more, the picker goes out (buttons) and the message waits for the tap.
     * The asking workspace's AI learns only the outcome, never the other workspaces.
     *
     * @return 'switched'|'picker_sent'|'single'
     */
    public function offerWorkspaces(Entity $subscription, string $text, int $messageId, string $key): string
    {
        $others = array_values(array_filter($this->subscriptions->activeForPhone((string) $subscription->get('phone')),
            fn (Entity $each) => $each->getId() !== $subscription->getId()));
        if (!$others) return 'single';
        if (count($others) === 1) {
            $this->assistant->ask($others[0], $text, $messageId);
            return 'switched';
        }
        foreach ($others as $each) {
            Sessions::setPending($each, $text, $messageId);
            $this->em->saveEntity($each, ['silent' => true]);
        }
        $this->choose($others, 'choose.ai', $key);
        return 'picker_sent';
    }

    /** @param list<Entity> $subscriptions one number in several workspaces: which deck, or which AI? */
    private function choose(array $subscriptions, string $copy = 'choose', ?string $key = null): void
    {
        $first = $subscriptions[0];
        $language = $this->workspaces->of($first)['language'];
        $items = [];
        foreach (array_slice($subscriptions, 0, 10) as $subscription) {
            $items[] = [$this->workspaces->of($subscription)['name'] ?: '—', $this->tokens->subscription($subscription->getId(), 'w')];
        }
        // Two or three workspaces fit reply buttons; more become a list.
        $this->avisos->buttons($first, 'reply', Copy::t($language, $copy), $items, null, $key);
    }

    private function markTapped(Entity $subscription, ?Entity $card): void
    {
        $log = $this->em->getRDBRepository('WhatsAppNotification')->where([
            'subscriptionId' => $subscription->getId(), 'direction' => 'out', 'tappedAt' => null,
            ...($card ? ['cardId' => $card->getId()] : []),
        ])->order('createdAt', 'DESC')->findOne();
        if (!$log) return;
        $log->set('tappedAt', gmdate('Y-m-d H:i:s'));
        $this->em->saveEntity($log);
    }

    private function status(object $data): void
    {
        $messageId = (int) ($data->id ?? 0);
        if ($messageId <= 0) return;
        $log = $this->em->getRDBRepository('WhatsAppNotification')
            ->where(['chatwootMessageId' => $messageId, 'direction' => 'out'])->order('createdAt', 'DESC')->findOne();
        if (!$log) return;
        $attributes = $data->content_attributes ?? null;
        if (is_string($attributes)) $attributes = json_decode($attributes);
        $status = is_string($data->status ?? null) ? $data->status : null;
        $current = (string) $log->get('status');
        if ($status === 'failed') {
            $log->set(['status' => 'failed', 'error' => mb_substr((string) ($attributes->external_error ?? 'failed'), 0, 255)]);
        } elseif (isset(self::STATUS_ORDER[$status]) && $current !== 'failed' && self::STATUS_ORDER[$status] > (self::STATUS_ORDER[$current] ?? 0)) {
            $log->set('status', $status);
        }
        $pricing = is_object($attributes) ? ($attributes->meta_pricing ?? null) : null;
        if (is_object($pricing)) {
            $log->set(['billable' => (bool) ($pricing->billable ?? false), 'pricingCategory' => is_string($pricing->category ?? null) ? $pricing->category : null]);
        }
        $this->em->saveEntity($log);
        if ($log->get('billable') && in_array($log->get('status'), ['delivered', 'read'], true) && !$log->get('chargedAt')) {
            $this->charges->charge($log);
        }
    }

    /** E.164 from what Chatwoot stores ("+5591…"), or null. */
    public static function phone(string $value): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $value);
        return strlen($digits) >= 10 && strlen($digits) <= 15 ? '+' . $digits : null;
    }
}
