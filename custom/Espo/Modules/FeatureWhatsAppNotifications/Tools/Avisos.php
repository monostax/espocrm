<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * The "Monostax - Avisos" inbox (WhatsApp Cloud, Monostax's own Chatwoot account): one thread per person, and every
 * message we send there logged in WhatsAppNotification, once per send key. Chatwoot sends to Meta; Meta's statuses
 * come back through the account webhook (Inbound::status).
 *
 * Inside Meta's 24h window (the person wrote or tapped in the last 24 hours) any message is free and may carry
 * buttons. Outside it only an approved template goes out.
 */
class Avisos
{
    /** A little under Meta's 24 hours, so a message queued at the edge doesn't miss the window. */
    public const WINDOW_SECONDS = 86400 - 900;
    public const BODY_LIMIT = 1024;
    private const BUTTON_TITLE = 20;
    private const ROW_TITLE = 24;

    /** @var ?array{platformUrl: string, apiKey: string, accountNumber: int, inboxNumber: int, accountId: string} */
    private ?array $connection = null;

    public function __construct(
        private EntityManager $em,
        private ChatwootApiClient $api,
        private Settings $settings,
        private Log $log,
    ) {}

    /** @return array{platformUrl: string, apiKey: string, accountNumber: int, inboxNumber: int, accountId: string} */
    public function connection(): array
    {
        if ($this->connection) return $this->connection;
        $inbox = $this->em->getEntityById('ChatwootInbox', $this->settings->inboxId());
        $account = $inbox ? $this->em->getEntityById('ChatwootAccount', (string) $inbox->get('chatwootAccountId')) : null;
        $platform = $account ? $this->em->getEntityById('ChatwootPlatform', (string) $account->get('platformId')) : null;
        if (!$inbox || !$account || !$platform || !$platform->get('backendUrl') || !$account->get('apiKey')) {
            throw new Error('WhatsApp notifications: the Avisos inbox is not configured.');
        }
        return $this->connection = [
            'platformUrl' => (string) $platform->get('backendUrl'),
            'apiKey' => (string) $account->get('apiKey'),
            'accountNumber' => (int) $account->get('chatwootAccountId'),
            'inboxNumber' => (int) $inbox->get('chatwootInboxId'),
            'accountId' => (string) $account->getId(),
        ];
    }

    /** Whether a webhook from this CRM ChatwootAccount and Chatwoot inbox is the Avisos thread. */
    public function isAvisos(string $espoAccountId, int $inboxNumber): bool
    {
        try {
            $connection = $this->connection();
        } catch (Throwable) {
            return false;
        }
        return $connection['accountId'] === $espoAccountId && $connection['inboxNumber'] === $inboxNumber;
    }

    public static function windowOpen(Entity $subscription): bool
    {
        $at = $subscription->get('lastInboundAt');
        return $at && strtotime($at . ' UTC') > time() - self::WINDOW_SECONDS;
    }

    public function text(Entity $subscription, string $kind, string $text, ?Entity $card = null, ?string $key = null): ?Entity
    {
        return $this->send($subscription, $kind, ['content' => self::cut($text, 4096)], $card, $key);
    }

    /**
     * A message with reply buttons (up to 3) or, with more, a list. Each item is [title, payload].
     *
     * @param list<array{string, string}> $items
     */
    public function buttons(Entity $subscription, string $kind, string $text, array $items, ?Entity $card = null, ?string $key = null): ?Entity
    {
        $limit = count($items) > 3 ? self::ROW_TITLE : self::BUTTON_TITLE;
        return $this->send($subscription, $kind, [
            'content' => self::cut($text, self::BODY_LIMIT),
            'content_type' => 'input_select',
            'content_attributes' => ['items' => array_map(fn (array $item) => ['title' => self::cut($item[0], $limit), 'value' => $item[1]], $items)],
        ], $card, $key);
    }

    /**
     * An approved template. $params are the body's {{1}}, {{2}}… in order; $payloads are the quick-reply buttons'
     * payloads in the template's button order; $imageUrl fills an IMAGE header (Meta fetches it when it sends). $content
     * is what Chatwoot shows in the thread.
     *
     * @param list<string> $params
     * @param list<string> $payloads
     */
    public function template(Entity $subscription, string $kind, string $name, string $content, array $params, array $payloads,
        ?Entity $card = null, ?string $key = null, ?string $imageUrl = null): ?Entity
    {
        $body = [];
        // A link goes as it is: stripping formatting characters would break it (utm_source).
        foreach (array_values($params) as $index => $value) {
            $body[(string) ($index + 1)] = preg_match('#^https://\S+$#', $value) ? $value : self::parameter($value);
        }
        $processed = [
            'body' => $body ?: new \stdClass(),
            'buttons' => array_map(fn (string $payload) => ['type' => 'quick_reply', 'parameter' => $payload], $payloads),
        ];
        if ($imageUrl !== null) $processed['header'] = ['media_url' => $imageUrl, 'media_type' => 'image'];
        return $this->send($subscription, $kind, [
            'content' => $content,
            'template_params' => [
                'name' => $name, 'category' => 'utility', 'language' => $this->settings->templateLanguage(),
                'processed_params' => $processed,
            ],
        ], $card, $key, $name);
    }

    /**
     * A free reply into an Avisos thread that has no subscription yet (a signup or Settings code just arrived): the
     * sender wrote, so the window is open.
     */
    public function reply(int $conversationId, string $phone, string $kind, string $text): void
    {
        $connection = $this->connection();
        try {
            $response = $this->api->accountRequest($connection['platformUrl'], $connection['apiKey'], 'POST',
                "/api/v1/accounts/{$connection['accountNumber']}/conversations/{$conversationId}/messages",
                ['message_type' => 'outgoing', 'private' => false, 'content' => $text]);
            $this->em->createEntity('WhatsAppNotification', [
                'direction' => 'out', 'kind' => $kind, 'sendKey' => $kind . ':' . bin2hex(random_bytes(10)), 'mode' => 'session',
                'phone' => $phone, 'chatwootMessageId' => isset($response['body']['id']) ? (int) $response['body']['id'] : null,
                'status' => $response['code'] >= 200 && $response['code'] < 300 ? 'queued' : 'failed',
            ]);
        } catch (Throwable $e) {
            $this->log->warning("WhatsApp notifications: {$kind} reply failed: " . $e->getMessage());
        }
    }

    /**
     * "digitando…" on the person's WhatsApp while the AI works, and their last message marked read: Chatwoot relays a
     * user's typing to WhatsApp Cloud (WhatsappTypingListener) for about a minute, or until our next message.
     */
    public function typing(Entity $subscription): void
    {
        $conversationId = (int) $subscription->get('avisosConversationId');
        if ($conversationId <= 0) return;
        try {
            $connection = $this->connection();
            $this->api->accountRequest($connection['platformUrl'], $connection['apiKey'], 'POST',
                "/api/v1/accounts/{$connection['accountNumber']}/conversations/{$conversationId}/toggle_typing_status",
                ['typing_status' => 'on']);
        } catch (Throwable $e) {
            $this->log->info("WhatsApp notifications: typing for subscription {$subscription->getId()} failed: " . $e->getMessage());
        }
    }

    /**
     * A template parameter as Meta accepts it: no line breaks, tabs or runs of 4+ spaces, no WhatsApp formatting
     * characters (the template wraps it in * or _ itself), never empty.
     */
    public static function parameter(string $value, int $limit = 400): string
    {
        $value = str_replace(['*', '_', '~', '`'], '', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        return self::cut($value === '' ? '—' : $value, $limit);
    }

    public static function cut(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : rtrim(mb_substr($text, 0, $limit - 1)) . '…';
    }

    /** @param array<string, mixed> $body */
    /**
     * A picture with a caption (WhatsApp shows up to 1024 characters under it): the card inside the 24h window. Chatwoot
     * takes the PNG as the message's attachment and sends it to WhatsApp as an image.
     */
    public function image(Entity $subscription, string $kind, string $caption, string $png, ?Entity $card = null, ?string $key = null): ?Entity
    {
        return $this->send($subscription, $kind, ['content' => self::cut($caption, 1024)], $card, $key, null, $png);
    }

    private function send(Entity $subscription, string $kind, array $body, ?Entity $card, ?string $key, ?string $template = null,
        ?string $png = null): ?Entity
    {
        $key ??= $kind . ':' . bin2hex(random_bytes(10));
        if ($this->em->getRDBRepository('WhatsAppNotification')->where(['sendKey' => $key])->findOne()) return null;
        try {
            $log = $this->em->createEntity('WhatsAppNotification', [
                'subscriptionId' => $subscription->getId(), 'userId' => $subscription->get('userId'),
                'tenantId' => $subscription->get('tenantId'), 'direction' => 'out', 'kind' => $kind, 'sendKey' => $key,
                'mode' => $template ? 'template' : 'session', 'template' => $template, 'cardId' => $card?->getId(),
                'phone' => $subscription->get('phone'), 'status' => 'queued',
            ]);
        } catch (Throwable) {
            // The unique send key: another worker logged this send first.
            return null;
        }
        try {
            $response = $this->post($subscription, $body, $png);
            if ($response['code'] === 404) {
                // The thread is gone (deleted in Chatwoot): start a new one once.
                $subscription->set('avisosConversationId', null);
                $response = $this->post($subscription, $body, $png);
            }
            if ($response['code'] >= 200 && $response['code'] < 300 && isset($response['body']['id'])) {
                $log->set('chatwootMessageId', (int) $response['body']['id']);
            } else {
                $log->set(['status' => 'failed', 'error' => mb_substr('Chatwoot HTTP ' . $response['code'] . ' ' . json_encode($response['body']), 0, 255)]);
            }
        } catch (Throwable $e) {
            $this->log->warning("WhatsApp notifications: {$kind} to subscription {$subscription->getId()} failed: " . $e->getMessage());
            $log->set(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 255)]);
        }
        $this->em->saveEntity($log);
        return $log;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{code: int, body: array<string, mixed>}
     */
    private function post(Entity $subscription, array $body, ?string $png = null): array
    {
        $connection = $this->connection();
        $conversationId = $this->conversation($subscription);
        $path = "/api/v1/accounts/{$connection['accountNumber']}/conversations/{$conversationId}/messages";
        if ($png === null) {
            return $this->api->accountRequest($connection['platformUrl'], $connection['apiKey'], 'POST', $path,
                ['message_type' => 'outgoing', 'private' => false, ...$body]);
        }
        // The account API client speaks JSON only; an attachment goes as multipart.
        $curl = curl_init(rtrim($connection['platformUrl'], '/') . $path);
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['api_access_token: ' . $connection['apiKey']],
            CURLOPT_POSTFIELDS => ['message_type' => 'outgoing', 'private' => 'false', 'content' => (string) ($body['content'] ?? ''),
                'attachments[]' => new \CURLStringFile($png, 'card.png', 'image/png')],
        ]);
        $raw = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return ['code' => $code, 'body' => is_array($decoded) ? $decoded : []];
    }

    /** The person's thread in the Avisos inbox: the one they last wrote in, an open one, or a new one. */
    private function conversation(Entity $subscription): int
    {
        $id = (int) $subscription->get('avisosConversationId');
        if ($id > 0) return $id;
        $connection = $this->connection();
        $contactId = (int) $subscription->get('avisosContactId');
        if ($contactId <= 0) {
            $user = $this->em->getEntityById('User', (string) $subscription->get('userId'));
            $contact = $this->api->findOrCreateContact($connection['platformUrl'], $connection['apiKey'], $connection['accountNumber'],
                $connection['inboxNumber'], (string) $subscription->get('phone'), $user?->get('name') ?: null);
            $contactId = (int) ($contact['id'] ?? 0);
            if ($contactId <= 0) throw new Error('WhatsApp notifications: no Chatwoot contact for the subscription.');
        }
        $list = $this->api->accountRequest($connection['platformUrl'], $connection['apiKey'], 'GET',
            "/api/v1/accounts/{$connection['accountNumber']}/contacts/{$contactId}/conversations");
        foreach (($list['body']['payload'] ?? []) as $conversation) {
            if ((int) ($conversation['inbox_id'] ?? 0) === $connection['inboxNumber'] && ($conversation['status'] ?? '') !== 'resolved') {
                $id = (int) ($conversation['id'] ?? 0);
                break;
            }
        }
        if ($id <= 0) {
            $created = $this->api->createConversation($connection['platformUrl'], $connection['apiKey'], $connection['accountNumber'],
                $contactId, $connection['inboxNumber']);
            $id = (int) ($created['id'] ?? 0);
        }
        if ($id <= 0) throw new Error('WhatsApp notifications: no Avisos conversation for the subscription.');
        $subscription->set(['avisosContactId' => $contactId, 'avisosConversationId' => $id]);
        $this->em->saveEntity($subscription, ['silent' => true]);
        return $id;
    }
}
