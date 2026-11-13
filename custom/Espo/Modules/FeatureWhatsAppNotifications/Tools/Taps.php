<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use DateTimeImmutable;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * The deck in WhatsApp: what each button and the person's text do. Typed text never acts on a card, except as the
 * reply after "Editar", which still needs a tap on Enviar. Sending to a customer always takes an explicit Enviar.
 */
class Taps
{
    public const EDIT_LIMIT = 4000;
    /** Card states in which its buttons still act. */
    private const OPEN = ['shown'];

    public function __construct(
        private EntityManager $em,
        private Deck $deck,
        private Cards $cards,
        private Acting $acting,
        private Avisos $avisos,
        private Workspaces $workspaces,
        private Subscriptions $subscriptions,
        private Tokens $tokens,
        private Log $log,
    ) {}

    /**
     * The person's next card, or "tudo em dia" ($sayDone). With the window closed only a card the template can say goes
     * out, as the template. Returns whether a card went out.
     *
     * @param ?callable(array<string, mixed>): bool $accept an extra filter on the stored card (hot leads)
     */
    public function next(Entity $subscription, string $kind = 'card', ?string $key = null, bool $sayDone = true, ?callable $accept = null): bool
    {
        $workspace = $this->workspaces->of($subscription);
        $language = $workspace['language'];
        $open = Avisos::windowOpen($subscription);
        if ($workspace['frozen']) {
            if ($open && $sayDone) $this->avisos->text($subscription, 'reply', Copy::t($language, 'frozen'));
            return false;
        }
        try {
            $card = $this->deck->next($subscription, $open ? Deck::DRAFT_VERDICTS : Deck::TEMPLATE_VERDICTS, $accept)['card'];
        } catch (Throwable $e) {
            $this->log->warning("WhatsApp notifications: deck for subscription {$subscription->getId()} failed: " . $e->getMessage());
            $card = null;
        }
        if (!$card) {
            if ($open && $sayDone) $this->avisos->text($subscription, 'reply', Copy::t($language, 'done'));
            return false;
        }
        $this->em->saveEntity($subscription, ['silent' => true]);
        return (bool) $this->cards->show($subscription, $card, $kind, $key);
    }

    /** @param array{kind: string, id: string, action: string} $token */
    public function subscriptionTap(Entity $subscription, array $token): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        match ($token['action']) {
            'n', 'w' => $this->next($subscription),
            'p' => $this->avisos->text($subscription, 'reply', $subscription->get('morningEnabled')
                ? Copy::t($language, 'pause.morning', ['time' => (string) $subscription->get('morningTime')])
                : Copy::t($language, 'pause')),
            'q' => $this->subscriptions->stopPhone((string) $subscription->get('phone'), 'keyword'),
            default => null,
        };
    }

    /** @param array{kind: string, id: string, action: string} $token */
    public function cardTap(Entity $subscription, Entity $card, array $token): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        $action = $token['action'];
        if ($action === 'o') {
            $this->cards->open($subscription, $card);
            return;
        }
        if ($action === 'n') {
            Sessions::skip($subscription, (string) $card->get('lane'), (string) $card->get('subjectId'));
            if (in_array($card->get('status'), self::OPEN, true)) $card->set('status', 'skipped');
            $this->save($subscription, $card);
            $this->next($subscription);
            return;
        }
        if ($action === 'x') {
            Sessions::setEditing($subscription, null);
            $this->save($subscription, $card);
            $this->cards->then($subscription, Copy::t($language, 'edit.cancelled'), 0);
            return;
        }
        if (!in_array($card->get('status'), self::OPEN, true)) {
            $this->cards->then($subscription, Copy::t($language, 'handled'), 0);
            return;
        }
        match ($action) {
            'v' => $this->suggestion($subscription, $card),
            'l' => $this->cards->later($subscription, $card),
            'h', 't', 'm' => $this->snooze($subscription, $card, $action),
            'r' => $this->resolve($subscription, $card),
            's' => $this->send($subscription, $card, (string) $card->get('draft')),
            'e' => $this->edit($subscription, $card),
            'c' => $card->get('editText') ? $this->send($subscription, $card, (string) $card->get('editText')) : $this->edit($subscription, $card),
            default => null,
        };
    }

    /** The person's text after "Editar": their reply, sent only after one confirmation. */
    public function edited(Entity $subscription, Entity $card, string $text): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::EDIT_LIMIT) {
            $this->avisos->text($subscription, 'reply', Copy::t($language, 'edit.tooLong'));
            return;
        }
        $card->set('editText', $text);
        $this->em->saveEntity($card);
        // No buttons to confirm with (2026-11-13): the edit is sent from the card in Em dia.
        Sessions::setEditing($subscription, null);
        $this->save($subscription, $card);
        $this->avisos->text($subscription, 'reply', Copy::t($language, 'edit.open', ['link' => $this->workspaces->cardLink($subscription, $card)]), $card);
    }

    private function suggestion(Entity $subscription, Entity $card): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        try {
            $fresh = $this->deck->refresh($subscription, $card);
        } catch (Throwable) {
            $fresh = null;
        }
        if (!$fresh) {
            if ($card->get('status') === 'shown') $card->set('status', 'stale');
            $this->em->saveEntity($card);
            $this->cards->then($subscription, Copy::t($language, 'changed'), 0);
            return;
        }
        $this->cards->suggestion($subscription, $fresh);
    }

    private function send(Entity $subscription, Entity $card, string $text): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        Sessions::setEditing($subscription, null);
        $this->em->saveEntity($subscription, ['silent' => true]);
        $status = $this->acting->send($subscription, $card, $text);
        if ($status === Acting::SENT) {
            $card->set('status', 'sent');
            Sessions::skip($subscription, (string) $card->get('lane'), (string) $card->get('subjectId'));
            $this->save($subscription, $card);
            $user = $this->em->getEntityById('User', (string) $subscription->get('userId'));
            $this->cards->then($subscription, Copy::t($language, 'sent', [
                'name' => (string) $card->get('contactName'), 'user' => (string) ($user?->get('firstName') ?: $user?->get('name') ?: ''),
            ]), $this->deck->left($subscription));
            return;
        }
        $this->failed($subscription, $card, $status);
    }

    private function resolve(Entity $subscription, Entity $card): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        $status = $this->acting->resolve($subscription, $card);
        if ($status === Acting::RESOLVED) {
            $card->set('status', 'resolved');
            Sessions::skip($subscription, (string) $card->get('lane'), (string) $card->get('subjectId'));
            $this->save($subscription, $card);
            $this->cards->then($subscription, Copy::t($language, 'resolved'), $this->deck->left($subscription));
            return;
        }
        $this->failed($subscription, $card, $status);
    }

    private function snooze(Entity $subscription, Entity $card, string $choice): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        $until = self::until($this->workspaces->now($subscription), $choice);
        if (!$this->acting->snooze($subscription, $card, $until)) {
            $this->failed($subscription, $card, Acting::NO_ACCESS);
            return;
        }
        $card->set(['status' => 'snoozed', 'remindAt' => $until->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        Sessions::skip($subscription, (string) $card->get('lane'), (string) $card->get('subjectId'));
        $this->save($subscription, $card);
        $this->cards->then($subscription, Copy::t($language, 'later.ok', ['when' => Copy::t($language, 'when.' . $choice)]), 0);
    }

    private function edit(Entity $subscription, Entity $card): void
    {
        Sessions::setEditing($subscription, $card->getId());
        $this->em->saveEntity($subscription, ['silent' => true]);
        $language = $this->workspaces->of($subscription)['language'];
        $this->avisos->text($subscription, 'reply', Copy::t($language, 'edit.ask', ['name' => (string) $card->get('contactName')]), $card);
    }

    private function failed(Entity $subscription, Entity $card, string $status): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        if ($status === Acting::CHANGED) {
            $card->set('status', 'stale');
            $this->save($subscription, $card);
        }
        $text = match ($status) {
            Acting::CHANGED => Copy::t($language, 'changed'),
            Acting::NO_ACCESS => Copy::t($language, 'noAccess'),
            Acting::FROZEN => Copy::t($language, 'frozen'),
            default => Copy::t($language, 'sendFailed', ['link' => $this->cards->link($subscription, $card)]),
        };
        $this->cards->then($subscription, $text, 0);
    }

    /** When "Me lembre mais tarde" brings the card back: in 1 hour, tomorrow at 8, or Monday at 8 (workspace time). */
    public static function until(DateTimeImmutable $now, string $choice): DateTimeImmutable
    {
        return match ($choice) {
            'h' => $now->modify('+1 hour'),
            't' => $now->modify('tomorrow 08:00'),
            default => $now->modify('next monday 08:00'),
        };
    }

    private function save(Entity $subscription, Entity $card): void
    {
        $this->em->saveEntity($card);
        $this->em->saveEntity($subscription, ['silent' => true]);
    }
}
