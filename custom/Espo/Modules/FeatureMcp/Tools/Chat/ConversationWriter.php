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

use Espo\Tools\OAuthServer\CurrentClient;

/**
 * Changing Chat as the person: everything shows in Chat as done by them, and messages an AI app writes carry
 * `content_attributes.sent_via_app` (the OAuth client's name), which the message shows as "via Claude".
 */
class ConversationWriter
{
    public const int MAX_TEXT = 20000;
    private const array STATUSES = ['open', 'pending', 'resolved', 'snoozed'];
    private const array PRIORITIES = ['urgent', 'high', 'medium', 'low'];

    public function __construct(
        private ChatClient $client,
        private CurrentClient $currentClient,
        private ConversationReader $reader,
    ) {}

    /**
     * @param int[] $mentionUserIds
     * @throws ChatError
     */
    public function note(ChatAccess $access, int $conversationId, string $text, array $mentionUserIds): array
    {
        $mentions = [];

        if ($mentionUserIds) {
            $agents = [];

            foreach ($this->reader->lookup($access, 'agents', null)['agents'] as $agent) {
                $agents[(int) $agent['id']] = (string) $agent['name'];
            }

            foreach (array_unique($mentionUserIds) as $id) {
                if (!isset($agents[$id])) {
                    return ['status' => 'not_applied', 'problem' => 'unknown_agent', 'agentId' => $id,
                        'explanation' => 'No such teammate in this workspace. List them with Conversations lookup agents.'];
                }

                $mentions[] = '[@' . $agents[$id] . '](mention://user/' . $id . '/' . rawurlencode($agents[$id]) . ')';
            }
        }

        $message = $this->client->post($access, "/conversations/$conversationId/messages", [
            'content' => trim(implode(' ', $mentions) . ' ' . $text),
            'private' => true,
            'content_attributes' => $this->tag(),
        ]);

        return [
            'status' => 'noted',
            'conversationId' => $conversationId,
            'messageId' => $message['id'] ?? null,
            'note' => 'Only the team sees it.',
        ];
    }

    /**
     * @param ?array<string, mixed> $template {name, language?, body?, header?, buttons?}
     * @throws ChatError
     */
    public function reply(ChatAccess $access, int $conversationId, ?string $text, ?array $template): array
    {
        $conversation = $this->client->get($access, '/conversations/' . $conversationId);
        $inboxId = (int) ($conversation['inbox_id'] ?? 0);
        $body = ['message_type' => 'outgoing', 'private' => false, 'content_attributes' => $this->tag()];

        if ($template) {
            $built = $this->template($access, $inboxId, $template);

            if (!$built['ok']) {
                unset($built['ok']);

                return ['status' => 'not_applied'] + $built;
            }

            $body += ['content' => $built['content'], 'template_params' => $built['params']];
        } else {
            if (($conversation['can_reply'] ?? true) === false) {
                $names = array_column($this->reader->templates($access, $inboxId)['templates'], 'name');

                return ['status' => 'not_applied', 'problem' => 'outside_window',
                    'explanation' => 'WhatsApp only takes a template here: the customer has not written in the last 24 hours.',
                    'templates' => $names ?: 'This inbox has no approved templates.'];
            }

            $body['content'] = $text;
        }

        $message = $this->client->post($access, "/conversations/$conversationId/messages", $body);

        return [
            'status' => ($message['status'] ?? null) === 'failed' ? 'failed' : 'sent',
            'conversationId' => $conversationId,
            'messageId' => $message['id'] ?? null,
            'text' => $body['content'],
        ] + (($message['status'] ?? null) === 'failed'
            ? ['error' => $message['content_attributes']['external_error'] ?? 'The channel refused it.']
            : ['note' => 'The channel delivers it now. If it refuses, the message shows as failed in the conversation.']);
    }

    /**
     * @param array{status?: ?string, snoozedUntil?: ?int, assigneeId?: ?int, unassign?: bool, teamId?: ?int,
     *     labels?: ?string[], addLabels?: ?string[], removeLabels?: ?string[], priority?: ?string} $change
     * @throws ChatError
     */
    public function act(ChatAccess $access, int $conversationId, array $change): array
    {
        $before = $this->client->get($access, '/conversations/' . $conversationId);
        $path = '/conversations/' . $conversationId;
        $done = [];

        if (($change['status'] ?? null) !== null) {
            if (!in_array($change['status'], self::STATUSES, true)) {
                throw new ChatError(400, 'Unknown status.');
            }

            $this->client->post($access, "$path/toggle_status", array_filter([
                'status' => $change['status'],
                'snoozed_until' => $change['status'] === 'snoozed' ? ($change['snoozedUntil'] ?? null) : null,
            ], fn ($it) => $it !== null));
            $done['status'] = ['before' => $before['status'] ?? null, 'after' => $change['status']];
        }

        if (($change['assigneeId'] ?? null) !== null || ($change['unassign'] ?? false)) {
            $this->client->post($access, "$path/assignments", ['assignee_id' => $change['unassign'] ?? false ? null : $change['assigneeId']]);
            $done['assignee'] = ['before' => $before['meta']['assignee']['name'] ?? null, 'afterId' => $change['unassign'] ?? false ? null : $change['assigneeId']];
        }

        if (($change['teamId'] ?? null) !== null) {
            $this->client->post($access, "$path/assignments", ['team_id' => $change['teamId'] ?: null]);
            $done['team'] = ['before' => $before['meta']['team']['name'] ?? null, 'afterId' => $change['teamId'] ?: null];
        }

        $current = is_array($before['labels'] ?? null) ? $before['labels'] : [];

        if (($change['labels'] ?? null) !== null || ($change['addLabels'] ?? null) || ($change['removeLabels'] ?? null)) {
            $labels = $change['labels'] ?? array_values(array_diff(
                array_unique([...$current, ...($change['addLabels'] ?? [])]),
                $change['removeLabels'] ?? []
            ));
            $this->client->post($access, "$path/labels", ['labels' => array_values($labels)]);
            $done['labels'] = ['before' => $current, 'after' => array_values($labels)];
        }

        if (array_key_exists('priority', $change) && $change['priority'] !== null) {
            $priority = $change['priority'] === 'none' ? null : $change['priority'];

            if ($priority !== null && !in_array($priority, self::PRIORITIES, true)) {
                throw new ChatError(400, 'Unknown priority.');
            }

            $this->client->post($access, "$path/toggle_priority", ['priority' => $priority]);
            $done['priority'] = ['before' => $before['priority'] ?? null, 'after' => $priority];
        }

        if (!$done) {
            return ['status' => 'not_applied', 'problem' => 'nothing_to_change',
                'explanation' => 'Pass status, assigneeId, unassign, teamId, labels, addLabels, removeLabels or priority.'];
        }

        return ['status' => 'changed', 'conversationId' => $conversationId, 'changes' => $done,
            'undo' => 'To undo, set the values under before again.'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: true, content: string, params: array<string, mixed>}|array<string, mixed>
     * @throws ChatError
     */
    private function template(ChatAccess $access, int $inboxId, array $input): array
    {
        $name = (string) ($input['name'] ?? '');
        $language = isset($input['language']) ? strtolower((string) $input['language']) : null;
        $matches = array_values(array_filter(
            Templates::approved($this->client->get($access, '/inboxes/' . $inboxId)),
            fn ($t) => ($t['name'] ?? null) === $name && ($language === null || strtolower((string) ($t['language'] ?? '')) === $language)
        ));

        if (count($matches) !== 1) {
            return ['ok' => false, 'problem' => $matches ? 'ambiguous_template' : 'unknown_template',
                'explanation' => $matches
                    ? 'That template exists in several languages; pass language.'
                    : 'No approved template with that name in this conversation\'s inbox. List them with Conversations lookup templates.'];
        }

        return Templates::build($matches[0], $input);
    }

    /** @return array<string, string> */
    private function tag(): array
    {
        return ['sent_via_app' => $this->currentClient->getName() ?: 'AI app'];
    }
}
