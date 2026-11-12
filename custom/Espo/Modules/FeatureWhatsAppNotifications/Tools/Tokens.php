<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

/**
 * Button payloads: "ed1.<kind>.<id>.<action>.<signature>". Meta hands the payload back on a tap, and Chatwoot passes
 * it on as `content_attributes.reply_payload`. Only a payload this instance signed acts, and only from the number of
 * the subscription it names (Inbound checks that). Every payload fits a WhatsApp list row id (200 characters).
 *
 * Kinds: `c` a card (WhatsAppDeckCard id), `s` a subscription (WhatsAppNotificationSubscription id).
 */
class Tokens
{
    private const PREFIX = 'ed1';

    /**
     * Card actions: see suggestion, open, resolved, remind menu, send, edit, confirm edit, cancel edit, remind in 1 h,
     * tomorrow 8h, Monday 8h, skip to the next card.
     */
    public const CARD_ACTIONS = ['v', 'o', 'r', 'l', 's', 'e', 'c', 'x', 'h', 't', 'm', 'n'];
    /** Subscription actions: next card, done for today, this workspace, stop. */
    public const SUBSCRIPTION_ACTIONS = ['n', 'p', 'w', 'q'];

    public function __construct(private Settings $settings) {}

    public function card(string $cardId, string $action): string
    {
        return $this->sign('c', $cardId, $action);
    }

    public function subscription(string $subscriptionId, string $action): string
    {
        return $this->sign('s', $subscriptionId, $action);
    }

    /** @return ?array{kind: string, id: string, action: string} */
    public function parse(?string $payload): ?array
    {
        if (!is_string($payload) || !preg_match('/^ed1\.([cs])\.([a-zA-Z0-9]{1,24})\.([a-z])\.([0-9a-f]{20})$/D', $payload, $m)) return null;
        [, $kind, $id, $action, $signature] = $m;
        $allowed = $kind === 'c' ? self::CARD_ACTIONS : self::SUBSCRIPTION_ACTIONS;
        if (!in_array($action, $allowed, true)) return null;
        if (!hash_equals($this->signature($kind, $id, $action), $signature)) return null;
        return ['kind' => $kind, 'id' => $id, 'action' => $action];
    }

    private function sign(string $kind, string $id, string $action): string
    {
        return implode('.', [self::PREFIX, $kind, $id, $action, $this->signature($kind, $id, $action)]);
    }

    private function signature(string $kind, string $id, string $action): string
    {
        return substr(hash_hmac('sha256', implode('.', [self::PREFIX, $kind, $id, $action]), $this->settings->secret()), 0, 20);
    }
}
