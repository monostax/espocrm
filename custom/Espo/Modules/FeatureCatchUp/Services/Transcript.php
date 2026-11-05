<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeImmutable;
use Espo\Core\Acl;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\ORM\Entity;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\EntityManager;

/**
 * A deal's conversations as the analysis reads them, straight from Chatwoot. The CRM's ChatwootMessage copy
 * is not used: its sync stores only each conversation's last message at every poll, so bursts and quick
 * back-and-forth go missing, and a card judged on that copy calls answered questions unanswered.
 */
class Transcript
{
    /** Newest messages and notes sent to the analysis; with the deal posts the backend accepts at most 50. */
    public const LIMIT = 40;
    /** Chatwoot's page size for conversation messages, and how many pages one conversation may take. */
    private const PAGE_SIZE = 20;
    private const PAGES = 5;
    /** Chatwoot message types the analysis reads; activity (2) is left out. */
    private const KINDS = [0 => 'incoming', 1 => 'outgoing', 3 => 'template'];
    /** Attachments read per message; the backend accepts at most 10. */
    private const ATTACHMENTS = 10;

    public function __construct(private EntityManager $em, private Acl $acl, private ChatwootApiClient $chatwoot) {}

    /**
     * Evidence entries, newest first, from the conversations whose messages the reader may read. Each
     * conversation passes the check one of its messages in the CRM would: the ChatwootMessage ACL (the
     * conversation's team and inbox access). A conversation that can't be read fails the analysis, since
     * judging the deal without it is what this class exists to avoid.
     *
     * @param array<string, Entity> $conversations
     * @return list<array<string, mixed>> entries carry `ts`, the Unix time, for ordering
     */
    public function read(array $conversations, DateTimeImmutable $now): array
    {
        if (!$conversations || !$this->acl->checkScope('ChatwootMessage', 'read') || !$this->acl->checkField('ChatwootMessage', 'content')) return [];
        $ai = null;
        $evidence = [];
        foreach ($conversations as $conversation) {
            $probe = $this->em->getNewEntity('ChatwootMessage');
            $probe->set(['conversationId' => $conversation->getId(), 'teamsIds' => $conversation->getLinkMultipleIdList('teams')]);
            if (!$this->acl->checkEntityRead($probe)) continue;
            $account = $this->em->getEntityById('ChatwootAccount', (string) $conversation->get('chatwootAccountId'));
            $platform = $account ? $this->em->getEntityById('ChatwootPlatform', (string) $account->get('platformId')) : null;
            if (!$account || !$platform?->get('backendUrl') || !$account->get('apiKey') || !$conversation->get('chatwootConversationId')) {
                throw new ServiceUnavailable('This conversation cannot be read from Chatwoot.');
            }
            $ai ??= $this->aiSenders();
            foreach ($this->fetch($conversation, $account, $platform) as $message) {
                $evidence[] = [...$this->entry($message, $ai, $now),
                    'conversationId' => (int) $conversation->get('chatwootConversationId'), 'accountId' => (int) $account->get('chatwootAccountId')];
            }
        }
        usort($evidence, fn ($a, $b) => [$b['ts'], $b['messageId']] <=> [$a['ts'], $a['messageId']]);
        return array_slice($evidence, 0, self::LIMIT);
    }

    /** An instant in the reader's time zone with its offset, so the analysis quotes the times people saw. */
    public static function at(int $timestamp, DateTimeImmutable $now): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($now->getTimezone())->format('Y-m-d\TH:i:sP');
    }

    /** @return list<array<string, mixed>> the conversation's newest messages of the read kinds, oldest first */
    private function fetch(Entity $conversation, Entity $account, Entity $platform): array
    {
        $kept = [];
        $before = null;
        for ($page = 0; $page < self::PAGES && count($kept) < self::LIMIT; $page++) {
            try {
                $messages = $this->chatwoot->getConversationMessages((string) $platform->get('backendUrl'), (string) $account->get('apiKey'),
                    (int) $account->get('chatwootAccountId'), (int) $conversation->get('chatwootConversationId'), $before);
            } catch (\Throwable) {
                throw new ServiceUnavailable('Chatwoot is temporarily unavailable.');
            }
            $read = array_filter($messages, fn ($message) => array_key_exists((int) ($message['message_type'] ?? -1), self::KINDS));
            $kept = [...array_values($read), ...$kept];
            if (count($messages) < self::PAGE_SIZE) break;
            // Pages come oldest first; the next one ends before this one's oldest message.
            $before = (int) $messages[0]['id'];
        }
        return $kept;
    }

    /** @param array<string, mixed> $message @param array<int, true> $ai */
    private function entry(array $message, array $ai, DateTimeImmutable $now): array
    {
        $type = (int) $message['message_type'];
        $private = (bool) ($message['private'] ?? false);
        $sender = is_array($message['sender'] ?? null) ? $message['sender'] : [];
        $senderType = $sender['type'] ?? null;
        $sentBy = match (true) {
            $private => 'internal',
            $type === 0 => 'customer',
            $senderType === 'user' => isset($ai[(int) ($sender['id'] ?? 0)]) ? 'ai' : 'staff',
            $senderType !== null && $senderType !== 'contact' => 'ai',
            // No sender on an outgoing message: typed on the phone and mirrored in.
            default => 'outside',
        };
        $text = trim(strip_tags((string) ($message['content'] ?? '')));
        [$markers, $files] = $this->attachments($message['attachments'] ?? [], $text !== '');
        $name = $sender['name'] ?? $sender['available_name'] ?? null;
        $timestamp = (int) $message['created_at'];
        return [
            'id' => (string) $message['id'], 'text' => mb_substr(trim($text . ' ' . $markers), 0, 4000), 'at' => self::at($timestamp, $now), 'ts' => $timestamp,
            'kind' => $private ? 'note' : self::KINDS[$type],
            'author' => in_array($sentBy, ['ai', 'staff', 'internal'], true) && is_string($name) ? mb_substr($name, 0, 120) : null,
            'sentBy' => $sentBy, 'context' => false, 'messageId' => (int) $message['id'],
        ] + ($files ? ['attachments' => $files] : []);
    }

    /**
     * Each attachment becomes a marker in the text, `[attachment <id>: <type>]`, and images and documents are
     * offered to the analysis as files under that id; the backend picks the newest within its caps. A voice
     * note reads as its transcript. The transcriber writes an incoming note's transcript into the message
     * text and an outgoing one's into the attachment, so audio is offered as a file only when neither has it.
     *
     * @param list<array<string, mixed>> $attachments
     * @return array{string, list<array{id: string, fileType: string, url: string, size: ?int}>}
     */
    private function attachments(array $attachments, bool $hasText): array
    {
        $markers = [];
        $files = [];
        foreach (array_slice($attachments, 0, self::ATTACHMENTS) as $attachment) {
            $id = (string) ($attachment['id'] ?? '');
            $type = (string) ($attachment['file_type'] ?? 'file');
            $transcript = trim((string) ($attachment['transcribed_text'] ?? ''));
            $markers[] = $type === 'audio' && $transcript !== ''
                ? "[attachment $id: audio, transcript: " . mb_substr($transcript, 0, 1000) . ']'
                : "[attachment $id: $type]";
            $readable = in_array($type, ['image', 'file'], true) || ($type === 'audio' && $transcript === '' && !$hasText);
            $url = $attachment['data_url'] ?? null;
            if ($id === '' || !$readable || !is_string($url) || strlen($url) > 4096 || !preg_match('~^https?://~i', $url)) continue;
            $files[] = ['id' => $id, 'fileType' => $type, 'url' => $url,
                'size' => isset($attachment['file_size']) ? (int) $attachment['file_size'] : null];
        }
        return [implode(' ', $markers), $files];
    }

    /** Chatwoot user ids of AI agents, so their messages are labeled as the AI's. @return array<int, true> */
    private function aiSenders(): array
    {
        $ids = $this->em->getPDO()->query(
            'SELECT DISTINCT cu.chatwoot_user_id FROM chatwoot_account_user_membership m '
            . 'JOIN chatwoot_user cu ON cu.id = m.chatwoot_user_id AND cu.deleted = 0 WHERE m.deleted = 0 AND m.is_a_i = 1'
        )->fetchAll(\PDO::FETCH_COLUMN);
        return array_fill_keys(array_map('intval', $ids), true);
    }
}
