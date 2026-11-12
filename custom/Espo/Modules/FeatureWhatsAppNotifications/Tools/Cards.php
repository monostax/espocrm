<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Espo\ORM\Entity;

/**
 * How a card reads on WhatsApp. Outside the 24h window it goes as the `monostax_catchup_card` template (body {{1}} who,
 * {{2}} how long, {{3}} context, {{4}} headline; quick replies Ver sugestão de resposta / Abrir conversa / Já resolvi /
 * Me lembre mais tarde). Inside it, as a free message with the link in the text and three buttons.
 */
class Cards
{
    public function __construct(
        private Avisos $avisos,
        private Tokens $tokens,
        private Workspaces $workspaces,
        private Settings $settings,
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
        $language = $this->workspaces->of($subscription)['language'];
        $name = (string) ($card->get('contactName') ?: '—');
        $wait = $this->wait($card, $language);
        if (!Avisos::windowOpen($subscription) && self::templatable($card)) {
            $headline = Avisos::parameter((string) $card->get('headline'), 120);
            $context = Avisos::parameter((string) $card->get('context'), 400);
            $content = Copy::t($language, 'card.customer_waiting', ['name' => $name, 'wait' => $wait]) . "\n\n*{$headline}*\n\n_{$context}_";
            return $this->avisos->template($subscription, $kind, $this->settings->cardTemplate(), $content,
                [Avisos::parameter($name, 60), Avisos::parameter($wait, 30), $context, $headline],
                array_map(fn (string $action) => $this->tokens->card($card->getId(), $action), ['v', 'o', 'r', 'l']), $card, $key);
        }
        $parts = [Copy::t($language, 'card.' . $card->get('verdict'), ['name' => $name, 'wait' => $wait])];
        if ($card->get('headline')) $parts[] = '*' . Avisos::cut((string) $card->get('headline'), 200) . '*';
        if ($card->get('context')) $parts[] = '_' . Avisos::cut((string) $card->get('context'), 500) . '_';
        $parts[] = Copy::t($language, 'card.open', ['link' => $this->link($subscription, $card)]);
        return $this->avisos->buttons($subscription, $kind, implode("\n\n", $parts), [
            [Copy::t($language, 'btn.suggestion'), $this->tokens->card($card->getId(), 'v')],
            [Copy::t($language, 'btn.resolved'), $this->tokens->card($card->getId(), 'r')],
            [Copy::t($language, 'btn.later'), $this->tokens->card($card->getId(), 'l')],
        ], $card, $key);
    }

    /** The stored draft, with Enviar / Editar / Próximo. A draft too long for a button message goes first on its own. */
    public function suggestion(Entity $subscription, Entity $card): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        $name = (string) $card->get('contactName');
        $text = Copy::t($language, 'suggestion', ['name' => $name, 'draft' => (string) $card->get('draft')]);
        if (mb_strlen($text) > Avisos::BODY_LIMIT) {
            $this->avisos->text($subscription, 'reply', (string) $card->get('draft'), $card);
            $text = Copy::t($language, 'suggestion.long', ['name' => $name]);
        }
        $this->avisos->buttons($subscription, 'reply', $text, [
            [Copy::t($language, 'btn.send'), $this->tokens->card($card->getId(), 's')],
            [Copy::t($language, 'btn.edit'), $this->tokens->card($card->getId(), 'e')],
            [Copy::t($language, 'btn.next'), $this->tokens->card($card->getId(), 'n')],
        ], $card);
    }

    public function open(Entity $subscription, Entity $card): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        $this->avisos->buttons($subscription, 'reply',
            Copy::t($language, 'open', ['name' => (string) $card->get('contactName'), 'link' => $this->link($subscription, $card)]), [
                [Copy::t($language, 'btn.suggestion'), $this->tokens->card($card->getId(), 'v')],
                [Copy::t($language, 'btn.resolved'), $this->tokens->card($card->getId(), 'r')],
                [Copy::t($language, 'btn.next'), $this->tokens->card($card->getId(), 'n')],
            ], $card);
    }

    public function later(Entity $subscription, Entity $card): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        $this->avisos->buttons($subscription, 'reply', Copy::t($language, 'later.ask', ['name' => (string) $card->get('contactName')]), [
            [Copy::t($language, 'btn.hour'), $this->tokens->card($card->getId(), 'h')],
            [Copy::t($language, 'btn.tomorrow'), $this->tokens->card($card->getId(), 't')],
            [Copy::t($language, 'btn.monday'), $this->tokens->card($card->getId(), 'm')],
        ], $card);
    }

    /** After an action: what happened, how many are left, and Próximo / Por hoje é só. */
    public function then(Entity $subscription, string $text, int $left): void
    {
        $language = $this->workspaces->of($subscription)['language'];
        if ($left > 0) $text .= "\n" . ($left === 1 ? Copy::t($language, 'left.one') : Copy::t($language, 'left.many', ['count' => $left]));
        $this->avisos->buttons($subscription, 'reply', $text, [
            [Copy::t($language, 'btn.next'), $this->tokens->subscription($subscription->getId(), 'n')],
            [Copy::t($language, 'btn.pause'), $this->tokens->subscription($subscription->getId(), 'p')],
        ]);
    }

    public function link(Entity $subscription, Entity $card): string
    {
        return $this->workspaces->link($subscription, (int) $card->get('conversationId'));
    }

    private function wait(Entity $card, string $language): string
    {
        $since = $card->get('waitSince');
        return $since ? Copy::wait($language, time() - (int) strtotime($since . ' UTC')) : Copy::t($language, 'wait.some');
    }
}
