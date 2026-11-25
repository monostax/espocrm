<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMcp\Tools\Chat;

/**
 * Reading Chat as the person. Lists come from Chat's own conversation list (it applies the person's access, custom
 * roles included). Search and a contact's conversations are opened one by one first, because Chat's search checks
 * inbox membership only (see the AI Session's chat-reader): a conversation Chat will not open for them is left out.
 */
class ConversationReader
{
    private const int SEARCH_PAGE_SIZE = 15;
    private const int MAX_SEARCH_PAGE = 5;
    private const int TRANSCRIPT_PAGES = 3;
    private const int MESSAGE_PAGE_CHARS = 12000;
    private const int CONTACTS = 5;
    private const int CONTACT_CONVERSATIONS = 10;
    private const array STATUSES = ['open', 'pending', 'snoozed', 'resolved', 'all'];
    private const array ASSIGNEES = ['me', 'unassigned', 'all'];

    /** @var array<int, array<int, array{name: string, channel: string}>> */
    private array $inboxes = [];

    public function __construct(
        private ChatClient $client,
    ) {}

    /** @throws ChatError */
    public function list(ChatAccess $access, ?string $status, ?string $assignee, ?int $inboxId, ?string $label, int $page): array
    {
        $status = in_array($status, self::STATUSES, true) ? $status : 'open';
        $assignee = in_array($assignee, self::ASSIGNEES, true) ? $assignee : 'all';
        $query = ['status' => $status, 'assignee_type' => $assignee, 'page' => max(1, $page)];

        if ($inboxId) {
            $query['inbox_id'] = $inboxId;
        }

        if ($label) {
            $query['labels'] = [$label];
        }

        $data = $this->client->get($access, '/conversations', $query)['data'] ?? [];
        $rows = is_array($data['payload'] ?? null) ? $data['payload'] : [];

        return [
            'workspace' => $access->workspace,
            'filter' => ['status' => $status, 'assignee' => $assignee] + array_filter(['inboxId' => $inboxId, 'label' => $label]),
            'counts' => array_filter([
                'mine' => $data['meta']['mine_count'] ?? null,
                'unassigned' => $data['meta']['unassigned_count'] ?? null,
                'others' => $data['meta']['others_count'] ?? $data['meta']['all_count'] ?? null,
            ], fn ($it) => $it !== null),
            'conversations' => array_map(fn (array $row) => $this->summary($access, $row), $rows),
            'nextPage' => count($rows) >= 25 ? max(1, $page) + 1 : null,
        ];
    }

    /** @throws ChatError */
    public function search(ChatAccess $access, string $q, int $page, ?string $timeZone): array
    {
        $page = min(max(1, $page), self::MAX_SEARCH_PAGE);
        $body = $this->client->get($access, '/search/messages', ['q' => $q, 'page' => $page]);
        $hits = is_array($body['payload']['messages'] ?? null) ? $body['payload']['messages'] : [];
        $opened = $this->open($access, array_map(fn ($hit) => (int) ($hit['conversation_id'] ?? 0), $hits));
        $results = [];

        foreach ($hits as $hit) {
            $conversation = $opened['open'][(int) ($hit['conversation_id'] ?? 0)] ?? null;
            $from = Transcript::speaker($hit);

            if (!$conversation || !$from) {
                continue;
            }

            $results[] = array_filter([
                'conversationId' => (int) $hit['conversation_id'],
                'messageId' => $hit['id'] ?? null,
                'at' => Transcript::clock($hit['created_at'] ?? null, $timeZone),
                'from' => $from,
                'contact' => $conversation['meta']['sender']['name'] ?? null,
                'subject' => Transcript::subject($hit) ?: null,
                'snippet' => Transcript::snippet(Transcript::text($hit), $q),
            ], fn ($it) => $it !== null);
        }

        return [
            'workspace' => $access->workspace,
            'query' => $q,
            'page' => $page,
            'results' => $results,
            'nextPage' => count($hits) >= self::SEARCH_PAGE_SIZE && $page < self::MAX_SEARCH_PAGE ? $page + 1 : null,
            'searches' => 'Messages of the last 3 months, newest first.',
        ] + ($opened['unchecked'] ? ['note' => "Some matches could not be checked and are not shown ({$opened['unchecked']} conversations)."] : []);
    }

    /** Conversations of the contacts matching a name, phone or email. @throws ChatError */
    public function byContact(ChatAccess $access, string $contact): array
    {
        $found = $this->client->get($access, '/contacts/search', ['q' => $contact, 'page' => 1]);
        $contacts = array_slice(is_array($found['payload'] ?? null) ? $found['payload'] : [], 0, self::CONTACTS);
        $list = [];

        foreach ($contacts as $row) {
            try {
                $conversations = $this->client->get($access, '/contacts/' . (int) $row['id'] . '/conversations')['payload'] ?? [];
            } catch (ChatError $e) {
                if ($e->denied()) {
                    continue;
                }

                throw $e;
            }

            $ids = array_slice(array_map(fn ($it) => (int) ($it['id'] ?? 0), is_array($conversations) ? $conversations : []), 0, self::CONTACT_CONVERSATIONS);
            $opened = $this->open($access, $ids);
            $items = array_values(array_map(fn ($it) => $this->summary($access, $it), $opened['open']));

            if ($items) {
                $list[] = [
                    'contact' => array_filter([
                        'name' => $row['name'] ?? null,
                        'phone' => $row['phone_number'] ?? null,
                        'email' => $row['email'] ?? null,
                    ]),
                    'conversations' => $items,
                ];
            }
        }

        return ['workspace' => $access->workspace, 'contacts' => $list] +
            ($list ? [] : ['hint' => 'No conversation you can open for that contact. Try the phone with country code, or search the messages with q.']);
    }

    /** @throws ChatError */
    public function read(ChatAccess $access, int $conversationId, ?int $before, ?string $timeZone): array
    {
        $conversation = $this->client->get($access, '/conversations/' . $conversationId);
        $messages = [];
        $cursor = $before;
        $earlier = null;

        for ($i = 0; $i < self::TRANSCRIPT_PAGES; $i++) {
            $page = $this->client->get($access, "/conversations/$conversationId/messages", $cursor ? ['before' => $cursor] : []);
            $rows = is_array($page['payload'] ?? null) ? $page['payload'] : [];

            if (!$rows) {
                break;
            }

            $messages = [...$rows, ...$messages];
            $cursor = (int) ($rows[0]['id'] ?? 0) ?: null;
            $earlier = count($rows) >= 20 ? $cursor : null;

            if (!$earlier || mb_strlen(implode(' ', array_map(fn ($m) => (string) ($m['content'] ?? ''), $messages))) > Transcript::CHARS) {
                break;
            }
        }

        $transcript = Transcript::format($messages, $earlier, $timeZone);

        return [
            'conversation' => $this->summary($access, $conversation) + array_filter([
                'snoozedUntil' => isset($conversation['snoozed_until']) ? Transcript::clock($conversation['snoozed_until'], $timeZone) : null,
            ]),
            'timeZone' => $timeZone,
            'transcript' => $transcript['lines'],
        ] + ($transcript['earlierBefore'] ? ['earlier' => ['before' => $transcript['earlierBefore'],
            'how' => 'Read again with this before to see older messages.']] : []);
    }

    /** One message's whole text, from offset. @throws ChatError */
    public function message(ChatAccess $access, int $conversationId, int $messageId, int $offset, ?string $timeZone): array
    {
        $page = $this->client->get($access, "/conversations/$conversationId/messages", ['around' => $messageId]);

        foreach (is_array($page['payload'] ?? null) ? $page['payload'] : [] as $message) {
            if ((int) ($message['id'] ?? 0) !== $messageId) {
                continue;
            }

            $text = Transcript::text($message);
            $total = mb_strlen($text);

            return [
                'conversationId' => $conversationId,
                'messageId' => $messageId,
                'from' => Transcript::speaker($message),
                'at' => Transcript::clock($message['created_at'] ?? null, $timeZone),
                'text' => mb_substr($text, $offset, self::MESSAGE_PAGE_CHARS),
                'totalChars' => $total,
            ] + ($offset + self::MESSAGE_PAGE_CHARS < $total ? ['nextOffset' => $offset + self::MESSAGE_PAGE_CHARS] : []);
        }

        throw new ChatError(404, 'No such message in that conversation.');
    }

    /** @throws ChatError */
    public function lookup(ChatAccess $access, string $kind, ?int $inboxId): array
    {
        return match ($kind) {
            'agents' => ['agents' => array_map(fn ($it) => [
                'id' => $it['id'] ?? null, 'name' => $it['name'] ?? null, 'role' => $it['role'] ?? null,
                'availability' => $it['availability_status'] ?? null,
            ], $this->items($this->client->get($access, '/agents')))],
            'teams' => ['teams' => array_map(fn ($it) => [
                'id' => $it['id'] ?? null, 'name' => $it['name'] ?? null,
            ], $this->items($this->client->get($access, '/teams')))],
            'labels' => ['labels' => array_map(fn ($it) => $it['title'] ?? null, $this->items($this->client->get($access, '/labels')))],
            'inboxes' => ['inboxes' => array_map(
                fn ($id, $it) => ['id' => $id, 'name' => $it['name'], 'channel' => $it['channel']],
                array_keys($this->inboxes($access)),
                $this->inboxes($access)
            )],
            'templates' => $this->templates($access, (int) $inboxId),
            default => throw new ChatError(400, 'Unknown lookup.'),
        };
    }

    /**
     * Approved WhatsApp templates of an inbox, with what each needs to be sent.
     *
     * @return array<string, mixed>
     * @throws ChatError
     */
    public function templates(ChatAccess $access, int $inboxId): array
    {
        if ($inboxId <= 0) {
            throw new ChatError(400, 'Templates need the inbox (inboxId or conversationId).');
        }

        $list = [];

        foreach (Templates::approved($this->client->get($access, '/inboxes/' . $inboxId)) as $template) {
            $list[] = Templates::describe($template);
        }

        return ['inboxId' => $inboxId, 'templates' => $list] +
            ($list ? [] : ['note' => 'This inbox has no approved WhatsApp templates.']);
    }

    /**
     * Opens each conversation as the person; the ones Chat refuses are dropped.
     *
     * @param int[] $ids
     * @return array{open: array<int, array<string, mixed>>, unchecked: int}
     */
    private function open(ChatAccess $access, array $ids): array
    {
        $open = [];
        $unchecked = 0;

        foreach (array_unique(array_filter($ids)) as $id) {
            try {
                $open[$id] = $this->client->get($access, '/conversations/' . $id);
            } catch (ChatError $e) {
                if (!$e->denied()) {
                    $unchecked++;
                }
            }
        }

        return ['open' => $open, 'unchecked' => $unchecked];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function summary(ChatAccess $access, array $row): array
    {
        $inbox = $this->inboxes($access)[(int) ($row['inbox_id'] ?? 0)] ?? null;
        $last = $row['last_non_activity_message'] ?? (is_array($row['messages'] ?? null) ? end($row['messages']) : null);

        return array_filter([
            'conversationId' => $row['id'] ?? null,
            'contact' => array_filter([
                'name' => $row['meta']['sender']['name'] ?? null,
                'phone' => $row['meta']['sender']['phone_number'] ?? null,
                'email' => $row['meta']['sender']['email'] ?? null,
            ]) ?: null,
            'inbox' => $inbox ? ['id' => (int) $row['inbox_id'], 'name' => $inbox['name'], 'channel' => $inbox['channel']] : null,
            'status' => $row['status'] ?? null,
            'priority' => $row['priority'] ?? null,
            'assignee' => $row['meta']['assignee']['name'] ?? null,
            'team' => $row['meta']['team']['name'] ?? null,
            'labels' => ($row['labels'] ?? []) ?: null,
            'unread' => ($row['unread_count'] ?? 0) ?: null,
            'canReply' => $row['can_reply'] ?? null,
            'lastMessage' => is_array($last) && Transcript::speaker($last) ? [
                'from' => Transcript::speaker($last),
                'text' => Transcript::snippet(Transcript::text($last), ''),
            ] : null,
        ], fn ($it) => $it !== null);
    }

    /** @return array<int, array{name: string, channel: string}> @throws ChatError */
    private function inboxes(ChatAccess $access): array
    {
        if (!isset($this->inboxes[$access->accountId])) {
            $this->inboxes[$access->accountId] = [];

            foreach ($this->items($this->client->get($access, '/inboxes')) as $inbox) {
                $this->inboxes[$access->accountId][(int) ($inbox['id'] ?? 0)] = [
                    'name' => (string) ($inbox['name'] ?? ''),
                    'channel' => str_replace('Channel::', '', (string) ($inbox['channel_type'] ?? '')),
                ];
            }
        }

        return $this->inboxes[$access->accountId];
    }

    /** @param array<mixed> $body @return list<array<string, mixed>> Chat lists come bare or under payload. */
    private function items(array $body): array
    {
        $list = array_is_list($body) ? $body : ($body['payload'] ?? []);

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }
}
