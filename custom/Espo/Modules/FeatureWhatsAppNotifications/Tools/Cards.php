<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Espo\ORM\Entity;

/**
 * How a card reads on WhatsApp: no buttons (the user, 2026-11-13), only the link that opens Em dia on the card, where
 * the person acts. With `cardImage` (the user's format, 2026-11-13) it is the rendered card (CardImages, 1:1 with the
 * suggested reply) and two lines, nothing more:
 *
 *   🔥 *Sara Ferreira* está esperando resposta há mais de *8 horas*.
 *
 *   https://chat.app.monostax.ai/app/accounts/6/catch-up?card=service:…&utm_source=whatsapp
 *
 * Inside the 24h window that is exactly the picture with that caption. Outside it the card template has to say a bit
 * more: Meta files the bare two lines as MARKETING (monostax_emdia_card_v2/v3), so it is monostax_catchup_card_link
 * (UTILITY: IMAGE header, "🔥 … {{1}} … {{2}}.", "Abra o card no Em dia: {{3}}", "A sugestão de resposta já está pronta
 * lá."), with the link and its utm as {{3}} (the user, 2026-11-13). Without `cardImage`: `monostax_catchup_card` (body {{1}} who, {{2}} how long, {{3}} context, {{4}} headline)
 * outside the window, a free text with the link inside it.
 *
 * With `cardButton` (the user, 2026-11-19) the link becomes Meta's "Abrir no Em dia" button: inside the window an
 * interactive cta_url with the card as its image header, an invisible body and the workspace as its footer; outside it
 * monostax_emdia_card_button (IMAGE header, "🔥 *{{1}}* está esperando resposta há mais de *{{2}}*. 👇", footer
 * "Monostax · Em dia", URL button) with the link as the button's {{1}}. The 👇 is there because Meta refuses a body that
 * ends on a variable (2388299); the user wanted the line alone, without "A sugestão de resposta já está pronta".
 */
class Cards
{
    /** Appended to the card link in every message. */
    public const UTM = '&utm_source=whatsapp';
    /**
     * The body of the link-button card: Meta requires one and the picture already says it all, so a Hangul filler
     * (U+3164), which WhatsApp shows as nothing (the user picked it over a zero-width space, 2026-11-19).
     */
    public const BLANK = "\u{3164}";
    /** The card template's URL button is https://chat.app.monostax.ai/app/accounts/{{1}}: the link after this. */
    private const BUTTON_PATH = '/app/accounts/';

    public function __construct(
        private Avisos $avisos,
        private Workspaces $workspaces,
        private Settings $settings,
        private CardImages $images,
    ) {}

    /** Whether this card can go as the template: the template says the customer is waiting on us. */
    public static function templatable(Entity $card): bool
    {
        return in_array($card->get('verdict'), Deck::TEMPLATE_VERDICTS, true);
    }

    /**
     * Send a card: a free message when the window is open, else the template (only for a card it can say; the caller
     * picks those). Returns the delivery log, or null when this send key already went out.
     */
    public function show(Entity $subscription, Entity $card, string $kind, ?string $key = null): ?Entity
    {
        $workspace = $this->workspaces->of($subscription);
        $language = $workspace['language'];
        $name = (string) ($card->get('contactName') ?: '—');
        $wait = $this->wait($card, $language);
        $link = $this->workspaces->cardLink($subscription, $card);
        if ($this->settings->cardButton()) {
            $line = Copy::t($language, 'card.' . $card->get('verdict'), ['name' => $name, 'wait' => $wait]);
            $url = $link . self::UTM;
            if (!Avisos::windowOpen($subscription)) {
                $content = $line . " 👇\n\n" . $url;
                return $this->avisos->template($subscription, $kind, $this->settings->cardTemplate(), $content,
                    [Avisos::parameter($name, 60), Avisos::parameter($wait, 30)], [], $card, $key, $this->images->url($card), self::urlSuffix($url));
            }
            return $this->avisos->cta($subscription, $kind, $line . "\n\n" . $url, self::BLANK, Copy::t($language, 'btn.openCard'), $url,
                $this->images->url($card), (string) ($workspace['name'] ?? ''), $card, $key);
        }
        if ($this->settings->cardImage()) {
            $line = Copy::t($language, 'card.' . $card->get('verdict'), ['name' => $name, 'wait' => $wait]);
            $caption = $line . "\n\n" . $link . self::UTM;
            if (!Avisos::windowOpen($subscription)) {
                $content = $line . "\n\n" . Copy::t($language, 'card.templateOpen', ['link' => $link . self::UTM]);
                return $this->avisos->template($subscription, $kind, $this->settings->cardTemplate(), $content,
                    [Avisos::parameter($name, 60), Avisos::parameter($wait, 30), $link . self::UTM], [], $card, $key, $this->images->url($card));
            }
            $png = $this->images->render((string) $card->getId());
            return $png !== null
                ? $this->avisos->image($subscription, $kind, $caption, $png, $card, $key)
                : $this->avisos->text($subscription, $kind, $caption, $card, $key);
        }
        if (!Avisos::windowOpen($subscription) && self::templatable($card)) {
            $headline = Avisos::parameter((string) $card->get('headline'), 120);
            $context = Avisos::parameter((string) $card->get('context'), 400);
            $content = Copy::t($language, 'card.customer_waiting', ['name' => $name, 'wait' => $wait]) . "\n\n*{$headline}*\n\n_{$context}_\n\n"
                . Copy::t($language, 'card.open', ['link' => $link]);
            return $this->avisos->template($subscription, $kind, $this->settings->cardTemplate(), $content,
                [Avisos::parameter($name, 60), Avisos::parameter($wait, 30), $context, $headline], [], $card, $key);
        }
        $parts = [Copy::t($language, 'card.' . $card->get('verdict'), ['name' => $name, 'wait' => $wait])];
        if ($card->get('headline')) $parts[] = '*' . Avisos::cut((string) $card->get('headline'), 200) . '*';
        if ($card->get('context')) $parts[] = '_' . Avisos::cut((string) $card->get('context'), 500) . '_';
        $parts[] = Copy::t($language, 'card.open', ['link' => $link]);
        return $this->avisos->text($subscription, $kind, implode("\n\n", $parts), $card, $key);
    }

    /**
     * The stored draft and the card link to send it from (a tap on a button of a message sent before buttons went away).
     * A draft too long for one message goes first on its own.
     */
    public function suggestion(Entity $subscription, Entity $card): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        $name = (string) $card->get('contactName');
        $open = Copy::t($language, 'suggestion.open', ['link' => $this->workspaces->cardLink($subscription, $card)]);
        $text = Copy::t($language, 'suggestion', ['name' => $name, 'draft' => (string) $card->get('draft')]) . "\n\n" . $open;
        if (mb_strlen($text) > Avisos::BODY_LIMIT) {
            $this->avisos->text($subscription, 'reply', (string) $card->get('draft'), $card);
            $text = Copy::t($language, 'suggestion.long', ['name' => $name]) . "\n\n" . $open;
        }
        $this->avisos->text($subscription, 'reply', $text, $card);
    }

    public function open(Entity $subscription, Entity $card): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        $this->avisos->text($subscription, 'reply',
            Copy::t($language, 'open', ['name' => (string) $card->get('contactName'), 'link' => $this->workspaces->cardLink($subscription, $card)]), $card);
    }

    /** Putting a card off happens in Em dia (Adiar sugestão): the link to it. */
    public function later(Entity $subscription, Entity $card): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        $this->avisos->text($subscription, 'reply', Copy::t($language, 'later.open', ['link' => $this->workspaces->cardLink($subscription, $card)]), $card);
    }

    /** After an action: what happened, how many are left, and how to ask for the next one. */
    public function then(Entity $subscription, string $text, int $left): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        if ($left > 0) $text .= "\n" . ($left === 1 ? Copy::t($language, 'left.one') : Copy::t($language, 'left.many', ['count' => $left]));
        $this->avisos->text($subscription, 'reply', $text . "\n" . Copy::t($language, 'then.more'));
    }

    public function link(Entity $subscription, Entity $card): string
    {
        return $this->workspaces->link($subscription, (int) $card->get('conversationId'));
    }

    public static function urlSuffix(string $url): string
    {
        $at = strpos($url, self::BUTTON_PATH);
        return $at === false ? $url : substr($url, $at + strlen(self::BUTTON_PATH));
    }

    private function wait(Entity $card, string $language): string
    {
        $since = $card->get('waitSince');
        return $since ? Copy::wait($language, time() - (int) strtotime($since . ' UTC')) : Copy::t($language, 'wait.some');
    }
}
