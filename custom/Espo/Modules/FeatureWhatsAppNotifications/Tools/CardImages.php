<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * The card as a picture for the header of the card template (`cardImage`). The template carries a public link that
 * Meta fetches when it sends: signed per card with the module's secret and valid for a day, since the picture shows
 * the customer's name and the conversation's context. The backend (Satori + resvg) draws it from what the card says.
 */
class CardImages
{
    /** How long the link works after the send: Meta fetches it at once, retries stay well inside a day. */
    public const TTL = 86400;
    private const TIMEOUT = 10;

    public function __construct(
        private EntityManager $em,
        private Settings $settings,
        private Config $config,
        private Workspaces $workspaces,
        private Log $log,
    ) {}

    public function url(Entity $card, ?int $now = null): string
    {
        $id = (string) $card->getId();
        $expires = ($now ?? time()) + self::TTL;
        $base = rtrim((string) $this->config->get('siteUrl'), '/');
        return "{$base}/api/v1/WhatsAppNotifications/cardImage/{$id}.{$expires}.{$this->signature($id, $expires)}.png";
    }

    /** The card id behind a link, or null when it is forged, for another card, or expired. */
    public function cardId(string $token, ?int $now = null): ?string
    {
        if (!preg_match('/^([a-zA-Z0-9]{1,24})\.(\d{10})\.([0-9a-f]{32})\.png$/D', $token, $m)) return null;
        [, $id, $expires, $signature] = $m;
        if ((int) $expires < ($now ?? time()) || !hash_equals($this->signature($id, (int) $expires), $signature)) return null;
        return $id;
    }

    /** The PNG, or null when the card is gone or the backend can't draw it. */
    public function render(string $cardId): ?string
    {
        $card = $this->em->getEntityById('WhatsAppDeckCard', $cardId);
        $subscription = $card ? $this->em->getEntityById('WhatsAppNotificationSubscription', (string) $card->get('subscriptionId')) : null;
        if (!$card || !$subscription) return null;
        try {
            return $this->post($this->payload($card, $this->workspaces->of($subscription)));
        } catch (Throwable $e) {
            $this->log->warning("WhatsApp notifications: card image {$cardId} failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * What the backend draws: who and how long idle (header), the headline and the context (content), and on a deal
     * the funnel with its icon, the stage, the amount and the owner (footer), square (1:1). The card was built in the person's own Em dia, so they may see all
     * of it.
     *
     * @param array{language: string, account: Entity} $workspace
     * @return array<string, mixed>
     */
    public function payload(Entity $card, array $workspace): array
    {
        $language = $workspace['language'];
        $since = $card->get('waitSince');
        $payload = [
            'name' => (string) ($card->get('contactName') ?: '—'),
            'idle' => Copy::t($language, 'image.idle', ['wait' => $since
                ? Copy::wait($language, time() - (int) strtotime($since . ' UTC')) : Copy::t($language, 'wait.some')]),
            'kind' => $card->get('lane') === 'deals' ? 'deal' : 'conversation',
            'headline' => (string) $card->get('headline'),
            'context' => (string) $card->get('context'),
            // The user's layout (2026-11-13): square, large type, no suggested reply (it lives in Em dia).
            'aspect' => '1:1',
        ];
        $deal = $card->get('lane') === 'deals' ? $this->em->getEntityById('Opportunity', (string) $card->get('subjectId')) : null;
        if ($deal) {
            $payload['deal'] = (string) $deal->get('name');
            $payload['details'] = array_values(array_filter([
                self::amount($deal->get('amount'), (string) $deal->get('amountCurrency'), $language),
                (string) $deal->get('assignedUserName'),
            ]));
            // The card's footer (the user, 2026-11-13): the funnel with its icon and the stage.
            $funnel = $deal->get('funnelId') ? $this->em->getEntityById('Funnel', (string) $deal->get('funnelId')) : null;
            $stage = $deal->get('opportunityStageId') ? $this->em->getEntityById('OpportunityStage', (string) $deal->get('opportunityStageId')) : null;
            if ($funnel) $payload['funnel'] = (string) $funnel->get('name');
            if ($stage) $payload['stage'] = (string) $stage->get('name');
            $icon = self::funnelIcon($funnel?->get('icon'));
            if ($icon) $payload['funnelIcon'] = $icon;
        }
        $conversation = $this->em->getRDBRepository('ChatwootConversation')->where([
            'chatwootAccountId' => $workspace['account']->getId(), 'chatwootConversationId' => (int) $card->get('conversationId'),
        ])->findOne();
        $avatar = (string) $conversation?->get('contactAvatarUrl');
        if (str_starts_with($avatar, 'https://')) $payload['avatarUrl'] = $avatar;
        return $payload;
    }

    /**
     * The funnel's icon as the renderer takes it: `{type: icon, value: "ti ti-stethoscope"}` or `{type: emoji, value}`.
     * The CRM stores it as JSON text (or an object, depending on how the record was read).
     *
     * @return ?array{type: string, value: string}
     */
    public static function funnelIcon(mixed $icon): ?array
    {
        if (is_string($icon)) $icon = json_decode($icon, true);
        if (is_object($icon)) $icon = (array) $icon;
        if (!is_array($icon) || !in_array($icon['type'] ?? null, ['icon', 'emoji'], true)) return null;
        $value = trim((string) ($icon['value'] ?? ''));
        return $value !== '' && mb_strlen($value) <= 80 ? ['type' => $icon['type'], 'value' => $value] : null;
    }

    /** "R$ 1.200" as Em dia shows it; cents only when there are any. Null without an amount. */
    public static function amount(mixed $value, string $currency, string $language): ?string
    {
        if ($value === null || $value === '') return null;
        $value = (float) $value;
        $decimals = abs($value - round($value)) < 0.005 ? 0 : 2;
        $number = $language === 'en_US'
            ? number_format($value, $decimals, '.', ',')
            : number_format($value, $decimals, ',', '.');
        $symbol = ['BRL' => 'R$', 'USD' => 'US$', 'EUR' => '€'][$currency ?: 'BRL'] ?? $currency;
        return "{$symbol} {$number}";
    }

    private function signature(string $id, int $expires): string
    {
        return substr(hash_hmac('sha256', "card-image.{$id}.{$expires}", $this->settings->secret()), 0, 32);
    }

    /** Signed like FeatureCatchUp's summary requests (sha256 HMAC over "timestamp.body"). */
    private function post(array $payload): ?string
    {
        $secret = (string) getenv('CATCH_UP_BACKEND_SECRET');
        $baseUrl = (string) getenv('CATCH_UP_BACKEND_URL');
        if ($secret === '' || $baseUrl === '') throw new \RuntimeException('Catch Up backend is not configured.');
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $curl = curl_init(rtrim($baseUrl, '/') . '/internal/catch-up/card-image');
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-Crm-Timestamp: {$timestamp}",
                'X-Crm-Signature: sha256=' . hash_hmac('sha256', "{$timestamp}.{$body}", $secret)],
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);
        if (!is_string($response) || $status !== 200 || !str_starts_with($type, 'image/png')) {
            throw new \RuntimeException("backend answered {$status}");
        }
        return $response;
    }
}
