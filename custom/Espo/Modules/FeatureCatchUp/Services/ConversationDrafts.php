<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Core\Api\Request;
use Espo\Modules\FeatureCatchUp\Classes\CardGone;
use Espo\Modules\FeatureCatchUp\Classes\DeckRequest;

/** Authorize native draft bubbles only for readers who may see the card's entire evidence. */
class ConversationDrafts
{
    private const REPLIES = ['customer_waiting', 'promise_due', 'follow_up_due', 'next_step_missing'];

    public function __construct(
        private Workspace $workspace,
        private ConversationRecord $record,
        private Feed $deals,
        private ServiceFeed $service,
        private Verdicts $verdicts,
        private ChatwootAccess $access,
    ) {}

    /** @return list<array{key: string, grant: string}> */
    public function read(Request $request): array
    {
        $account = $this->workspace->resolve($request)['account'];
        $conversation = $this->record->readableConversation($request, $account);
        $viewer = $this->access->chatwootUserId((string) $account->get('platformId'));
        $secret = getenv('CATCH_UP_BACKEND_SECRET');
        if (!$conversation || !$viewer || !$secret) return [];
        $id = $conversation->getId();
        $deal = $this->service->hasOpenDeal($id);
        $feed = $deal ? $this->deals : $this->service;
        $locale = Summary::locale($request->getQueryParam('locale'));
        $drafts = [];
        // The deck list enforces ownership, snoozes, age, current revisions and resolution. Only a judged
        // candidate needs a snapshot: its exact evidence scope (including deal notes) must match the reader's.
        foreach ($this->candidates($feed, $request, $id) as $item) {
            if (!$item['verdict']) continue;
            try {
                $snapshot = $feed->snapshot($item['id'], $request);
            } catch (CardGone) {
                continue; // Closed since the candidate list was read. Keep the other deals' drafts.
            }
            $target = $snapshot['conversation'];
            if (!$target || $target['accountId'] !== (int) $account->get('chatwootAccountId')
                || $target['conversationId'] !== (int) $conversation->get('chatwootConversationId')) continue;
            if (in_array($snapshot['party']['label'] ?? null, ['personal', 'team', 'automated'], true)) continue;
            $stored = $this->verdicts->find($deal ? 'Opportunity' : 'ChatwootConversation', $item['id'],
                $snapshot['revision'], $locale, $snapshot['evidenceScope'], true);
            if (!$stored || !in_array($stored['verdict'], self::REPLIES, true) || Verdicts::outdated($stored, $snapshot['closedDeals'] ?? [])) continue;
            $text = $stored['card']['draft'] ?? null;
            if (!is_string($text) || trim($text) === '') continue;
            $key = hash('sha256', json_encode([$item['id'], $snapshot['revision'], $snapshot['evidenceScope'], $locale, $text], JSON_THROW_ON_ERROR));
            $messages = array_filter($snapshot['evidence'], fn ($entry) => ($entry['conversationId'] ?? null) === $target['conversationId']
                && ($entry['accountId'] ?? null) === $target['accountId']);
            $claims = [$target['accountId'], $target['conversationId'], $viewer, time() + 60, $key, $text,
                max([0, ...array_column($messages, 'messageId')])];
            $body = base64_encode(json_encode($claims, JSON_THROW_ON_ERROR));
            $drafts[] = ['key' => $key, 'grant' => $body . '.' . hash_hmac('sha256', 'catch-up-draft-v1.' . $body, $secret)];
        }
        return $drafts;
    }

    /** A conversation can be linked to deals on more than one page. */
    private function candidates(Feed|ServiceFeed $feed, Request $request, string $id): iterable
    {
        $query = new DeckRequest(['accountId' => $request->getQueryParam('accountId')]);
        do {
            $page = $feed->list($query, $id);
            yield from $page['items'];
            $query = $query->withQuery('cursor', $page['cursor']);
        } while ($page['cursor'] !== null);
    }
}
