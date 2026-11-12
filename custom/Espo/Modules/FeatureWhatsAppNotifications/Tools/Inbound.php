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
 *   (signup or Settings), a stop word, the reply after "Editar", or anything else from a subscriber, which sends their
 *   next card ("person asks"). Every incoming message opens Meta's 24h window for that number. Messages from numbers
 *   with no subscription stay in the inbox for the Monostax team.
 * - `message_updated`: Meta's status for one of our messages (sent, delivered, read, failed) and whether Meta billed
 *   it, onto the delivery log; a billed delivery is charged in credits (Charges).
 */
class Inbound
{
    private const STOP_WORDS = ['parar', 'sair', 'stop', 'parar avisos', 'cancelar avisos'];
    private const STATUS_ORDER = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

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
        if (count($subscriptions) > 1) {
            $this->choose($subscriptions);
            return;
        }
        $this->taps->next($subscriptions[0]);
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

    /** @param list<Entity> $subscriptions one number in several workspaces: which deck? */
    private function choose(array $subscriptions): void
    {
        $first = $subscriptions[0];
        $language = $this->workspaces->of($first)['language'];
        $items = [];
        foreach (array_slice($subscriptions, 0, 10) as $subscription) {
            $items[] = [$this->workspaces->of($subscription)['name'] ?: '—', $this->tokens->subscription($subscription->getId(), 'w')];
        }
        // Two or three workspaces fit reply buttons; more become a list.
        $this->avisos->buttons($first, 'reply', Copy::t($language, 'choose'), $items);
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
