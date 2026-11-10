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
    /** People and groups sent to the analysis; the backend accepts at most 40. */
    private const PARTICIPANTS = 20;
    /** Reactions read per message; the backend accepts at most 10. */
    private const REACTIONS = 10;
    /** A quoted message older than the entries sent is quoted in this many characters. */
    private const QUOTE = 300;
    /** Quoted messages looked up in Chatwoot per read when they are older than every page fetched. */
    private const QUOTE_LOOKUPS = 3;

    public function __construct(private EntityManager $em, private Acl $acl, private ChatwootApiClient $chatwoot) {}

    /**
     * Evidence entries, newest first, from the conversations whose messages the reader may read. Each
     * conversation passes the check one of its messages in the CRM would: the ChatwootMessage ACL (the
     * conversation's team and inbox access). A conversation that can't be read fails the analysis, since
     * judging the deal without it is what this class exists to avoid.
     *
     * Participants are the groups among those conversations and the people writing in them, with the class
     * on their Chatwoot contact (Parties). A person labeled team writes for the business, so their messages
     * count as staff. Unlabeled groups and group members are marked for the analysis to classify. A deal's
     * own contact in a direct chat never is (a deal proves the customer); with $direct, for a conversation
     * without a deal (Atendimento), it is. The conversation's own contact or group is marked `own`.
     *
     * @param array<string, Entity> $conversations
     * @return array{evidence: list<array<string, mixed>>, participants: list<array<string, mixed>>}
     *   evidence entries carry `ts`, the Unix time, for ordering
     */
    public function read(array $conversations, DateTimeImmutable $now, bool $direct = false): array
    {
        if (!$conversations || !$this->acl->checkScope('ChatwootMessage', 'read') || !$this->acl->checkField('ChatwootMessage', 'content')) {
            return ['evidence' => [], 'participants' => []];
        }
        $ai = null;
        $evidence = [];
        $participants = [];
        $quotes = [];
        $sources = [];
        foreach ($conversations as $conversation) {
            if (!$this->readable($conversation)) continue;
            $account = $this->em->getEntityById('ChatwootAccount', (string) $conversation->get('chatwootAccountId'));
            $platform = $account ? $this->em->getEntityById('ChatwootPlatform', (string) $account->get('platformId')) : null;
            if (!$account || !$platform?->get('backendUrl') || !$account->get('apiKey') || !$conversation->get('chatwootConversationId')) {
                throw new ServiceUnavailable('This conversation cannot be read from Chatwoot.');
            }
            $ai ??= $this->aiSenders();
            ['contact' => $contact, 'messages' => $messages, 'quotes' => $fetched] = $this->fetch($conversation, $account, $platform);
            $quotes += $fetched;
            $sources[(int) $conversation->get('chatwootConversationId')] = [$account, $platform, (int) $conversation->get('chatwootConversationId')];
            $accountId = (int) $account->get('chatwootAccountId');
            $group = str_ends_with((string) ($contact['identifier'] ?? ''), '@g.us');
            if (($group || $direct) && isset($contact['id'])) {
                $participants[$accountId . ':' . $contact['id']] = [...self::participant($contact, $account, $group), 'own' => true];
            }
            foreach ($messages as $message) {
                $sender = is_array($message['sender'] ?? null) ? $message['sender'] : [];
                if ($group && (int) $message['message_type'] === 0 && ($sender['type'] ?? null) === 'contact' && isset($sender['id'])) {
                    $participants[$accountId . ':' . $sender['id']] ??= self::participant($sender, $account, false);
                }
                $evidence[] = [...$this->entry($message, $ai, $now, $group),
                    'conversationId' => (int) $conversation->get('chatwootConversationId'), 'accountId' => $accountId];
            }
        }
        usort($evidence, fn ($a, $b) => [$b['ts'], $b['messageId']] <=> [$a['ts'], $a['messageId']]);
        $evidence = array_slice($evidence, 0, self::LIMIT);
        // A reply to a message older than what is sent carries that message's text, so a "pode ser" reads against its price.
        $sent = array_flip(array_column($evidence, 'id'));
        $lookups = 0;
        foreach ($evidence as $index => $entry) {
            $quoted = $entry['replyTo'] ?? null;
            if ($quoted === null || isset($sent[$quoted])) continue;
            if (!isset($quotes[$quoted]) && $lookups < self::QUOTE_LOOKUPS && isset($sources[$entry['conversationId']])) {
                $lookups++;
                $quotes += $this->quote(...[...$sources[$entry['conversationId']], (int) $quoted]);
            }
            if (isset($quotes[$quoted])) $evidence[$index]['replyText'] = $quotes[$quoted];
        }
        return ['evidence' => $evidence, 'participants' => array_slice(array_values($participants), 0, self::PARTICIPANTS)];
    }

    /**
     * Whether the reader may read this conversation's messages: the check one of its messages in the CRM would
     * pass (the ChatwootMessage ACL: the conversation's team and inbox access).
     */
    public function readable(Entity $conversation): bool
    {
        if (!$this->acl->checkScope('ChatwootMessage', 'read') || !$this->acl->checkField('ChatwootMessage', 'content')) return false;
        $probe = $this->em->getNewEntity('ChatwootMessage');
        $probe->set(['conversationId' => $conversation->getId(), 'teamsIds' => $conversation->getLinkMultipleIdList('teams')]);
        return $this->acl->checkEntityRead($probe);
    }

    /**
     * Evidence as the next read will label it once these classes are on the contacts, so a verdict is stored
     * under the evidence scope its later reads compute.
     *
     * @param list<array<string, mixed>> $evidence
     * @param array<string, string> $classes contact id => class
     * @return list<array<string, mixed>>
     */
    public static function relabel(array $evidence, array $classes): array
    {
        return array_map(function (array $entry) use ($classes): array {
            $id = $entry['participantId'] ?? null;
            if ($id === null || !isset($classes[$id]) || !in_array($entry['sentBy'] ?? null, ['customer', 'staff'], true)) return $entry;
            return ['sentBy' => $classes[$id] === 'team' ? 'staff' : 'customer'] + $entry;
        }, $evidence);
    }

    /** A group or a person in one, keyed by its account: the same WhatsApp group is a different contact in each account. */
    private static function participant(array $contact, Entity $account, bool $group): array
    {
        $label = Parties::classOf($contact);
        return [
            'id' => (string) $contact['id'], 'name' => mb_substr((string) ($contact['name'] ?? ''), 0, 120) ?: null, 'group' => $group,
            'label' => $label, 'classify' => $label === null && empty($contact['custom_attributes'][Parties::KEY]),
            'account' => $account->getId(), 'accountId' => (int) $account->get('chatwootAccountId'),
        ];
    }

    /** An instant in the reader's time zone with its offset, so the analysis quotes the times people saw. */
    public static function at(int $timestamp, DateTimeImmutable $now): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($now->getTimezone())->format('Y-m-d\TH:i:sP');
    }

    /**
     * @return array{contact: ?array<string, mixed>, messages: list<array<string, mixed>>, quotes: array<string, string>} the
     *   conversation's contact, its newest messages of the read kinds, oldest first, and the text of every public message fetched
     */
    private function fetch(Entity $conversation, Entity $account, Entity $platform): array
    {
        $kept = [];
        $quotes = [];
        $contact = null;
        $before = null;
        for ($page = 0; $page < self::PAGES && count($kept) < self::LIMIT; $page++) {
            try {
                $result = $this->chatwoot->getConversationMessagesPage((string) $platform->get('backendUrl'), (string) $account->get('apiKey'),
                    (int) $account->get('chatwootAccountId'), (int) $conversation->get('chatwootConversationId'), $before);
            } catch (\Throwable) {
                throw new ServiceUnavailable('Chatwoot is temporarily unavailable.');
            }
            $contact ??= $result['contact'];
            $messages = $result['messages'];
            $read = array_filter($messages, fn ($message) => array_key_exists((int) ($message['message_type'] ?? -1), self::KINDS));
            $kept = [...array_values($read), ...$kept];
            foreach ($read as $message) {
                $text = trim(strip_tags((string) ($message['content'] ?? '')));
                if (isset($message['id']) && $text !== '' && empty($message['private'])) $quotes[(string) $message['id']] = mb_substr($text, 0, self::QUOTE);
            }
            if (count($messages) < self::PAGE_SIZE) break;
            // Pages come oldest first; the next one ends before this one's oldest message.
            $before = (int) $messages[0]['id'];
        }
        return ['contact' => $contact, 'messages' => $kept, 'quotes' => $quotes];
    }

    /** @param array<string, mixed> $message @param array<int, true> $ai */
    private function entry(array $message, array $ai, DateTimeImmutable $now, bool $group): array
    {
        $type = (int) $message['message_type'];
        $private = (bool) ($message['private'] ?? false);
        $sender = is_array($message['sender'] ?? null) ? $message['sender'] : [];
        $senderType = $sender['type'] ?? null;
        $fromContact = $type === 0 && $senderType === 'contact' && isset($sender['id']);
        $sentBy = match (true) {
            $private => 'internal',
            // Someone who works for the business writing from their own number, e.g. the doctor in a patient's group.
            $fromContact && Parties::classOf($sender) === 'team' => 'staff',
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
            // In a group several people write, so the customer's side is named too.
            'author' => (in_array($sentBy, ['ai', 'staff', 'internal'], true) || ($group && $fromContact)) && is_string($name) ? mb_substr($name, 0, 120) : null,
            'participantId' => $fromContact ? (string) $sender['id'] : null,
            'sentBy' => $sentBy, 'context' => false, 'messageId' => (int) $message['id'],
        ] + ($files ? ['attachments' => $files] : [])
            + (($replyTo = self::replyTo($message)) !== null ? ['replyTo' => $replyTo] : [])
            + (($reactions = self::reactions($message['reactions'] ?? [], $ai)) ? ['reactions' => $reactions] : []);
    }

    /**
     * The text of one public message older than every page fetched: the page that ends right after it. A lookup
     * that fails leaves the reply without its quote; it never fails the analysis.
     *
     * @return array<string, string>
     */
    private function quote(Entity $account, Entity $platform, int $conversationId, int $messageId): array
    {
        try {
            $page = $this->chatwoot->getConversationMessagesPage((string) $platform->get('backendUrl'), (string) $account->get('apiKey'),
                (int) $account->get('chatwootAccountId'), $conversationId, $messageId + 1);
        } catch (\Throwable) {
            return [];
        }
        foreach ($page['messages'] ?? [] as $message) {
            if ((int) ($message['id'] ?? 0) !== $messageId || !empty($message['private'])) continue;
            if (!array_key_exists((int) ($message['message_type'] ?? -1), self::KINDS)) continue;
            $text = trim(strip_tags((string) ($message['content'] ?? '')));
            return $text === '' ? [] : [(string) $messageId => mb_substr($text, 0, self::QUOTE)];
        }
        return [];
    }

    /** The message this one quotes (a WhatsApp reply), as Chatwoot keeps it in content_attributes.in_reply_to. */
    private static function replyTo(array $message): ?string
    {
        $attributes = $message['content_attributes'] ?? null;
        // The column is double-encoded in some rows: a JSON string holding the object.
        if (is_string($attributes)) $attributes = json_decode($attributes, true);
        $id = is_array($attributes) ? ($attributes['in_reply_to'] ?? null) : null;
        return (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0 ? (string) (int) $id : null;
    }

    /**
     * Emoji reactions and who sent them: the contact is the customer, the business is the AI or a person on the team.
     *
     * @param mixed $reactions Chatwoot's reactions payload
     * @param array<int, true> $ai
     * @return list<array{emoji: string, sentBy: string, author: ?string}>
     */
    private static function reactions(mixed $reactions, array $ai): array
    {
        $read = [];
        foreach (is_array($reactions) ? $reactions : [] as $reaction) {
            $emoji = is_array($reaction) ? trim((string) ($reaction['emoji'] ?? '')) : '';
            if ($emoji === '' || mb_strlen($emoji) > 16) continue;
            $byContact = ($reaction['actor_type'] ?? null) === 'contact';
            $name = $reaction['actor_name'] ?? null;
            $read[] = ['emoji' => $emoji,
                'sentBy' => $byContact ? 'customer' : (isset($ai[(int) ($reaction['performed_by_id'] ?? 0)]) ? 'ai' : 'staff'),
                'author' => is_string($name) && $name !== '' ? mb_substr($name, 0, 120) : null];
            if (count($read) >= self::REACTIONS) break;
        }
        return $read;
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
