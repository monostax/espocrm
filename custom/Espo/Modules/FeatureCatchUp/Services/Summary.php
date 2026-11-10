<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeImmutable;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Entities\User;

class Summary
{
    /** What the model may answer (prompt version 12). The card verdict comes from Loops, never straight from the model. */
    private const DEAL_ANSWERS = ['loops', 'decided', 'next_step_missing', 'none'];
    private const CONVERSATION_ANSWERS = ['loops', 'new_deal', 'none'];

    public function __construct(private User $user, private Verdicts $verdicts, private Parties $parties, private Records $records) {}

    /**
     * A deal's verdict: the stored one when this revision and evidence were already judged, otherwise the
     * backend's analysis, stored once it completes. An answer that isn't a valid verdict counts as `none`:
     * it was paid for, and the same state is not analysed again. The model keeps the record's loops; Loops turns
     * them and the clock into the card (customer_waiting, promise_due, follow_up_due) or into `scheduled` (Agenda,
     * no card). The same call classifies the conversation's unlabeled groups and group members; their labels go
     * onto the Chatwoot contacts, and the verdict is stored under the evidence scope the next read, with those
     * labels, will compute.
     *
     * @param ?array{lane: string, id: string, accountId: string, timeZone: string} $notify Analysis at settle (Settle): the
     *     backend calls back with this when the analysis finishes, so the CRM stores the verdict without anyone polling.
     */
    public function analyse(array $snapshot, string $locale, ?string $requestId = null, ?array $notify = null): array
    {
        $conversation = ($snapshot['type'] ?? 'opportunity') === 'conversation';
        $key = [$conversation ? 'ChatwootConversation' : 'Opportunity', $snapshot['id'], $snapshot['revision'], $locale, $snapshot['evidenceScope']];
        $stored = $this->verdicts->find(...$key);
        if ($stored) return ['status' => 'completed', 'cached' => true, ...$stored];
        $result = $this->request($snapshot, $locale, $requestId, $notify);
        if ($result['status'] !== 'completed') return [...$result, 'cached' => false, 'verdict' => null, 'reason' => null, 'card' => null];
        $card = is_array($result['card'] ?? null) ? $result['card'] : null;
        $classes = $this->parties->store($snapshot['participants'] ?? [], is_array($card['participants'] ?? null) ? $card['participants'] : []);
        if ($classes) $key[4] = Feed::evidenceScope(Transcript::relabel($snapshot['evidence'], $classes));
        // The updated record replaces the reader's record; its due promises decide the revision the next read computes.
        if (is_array($card['record'] ?? null) && !empty($snapshot['sourceScope'])) {
            $this->records->store((string) $snapshot['tenantId'], $key[0], $snapshot['id'], $snapshot['sourceScope'], $card['record'],
                Records::edge($snapshot['evidence']));
            if (isset($snapshot['revisions'])) {
                $due = $this->records->dues($key[0], [$snapshot['id']])[$snapshot['id']] ?? null;
                $key[2] = $due !== null && $due <= time() ? $snapshot['revisions']['due'] : $snapshot['revisions']['notDue'];
            }
        }
        $answer = in_array($card['verdict'] ?? null, $conversation ? self::CONVERSATION_ANSWERS : self::DEAL_ANSWERS, true) ? $card['verdict'] : 'none';
        $record = is_array($card['record'] ?? null) ? $card['record'] : ($snapshot['record'] ?? null);
        ['verdict' => $verdict, 'loop' => $loop] = Loops::verdict($answer, $record, is_array($snapshot['facts'] ?? null) ? $snapshot['facts'] : [],
            new DateTimeImmutable('@' . time()), !empty($snapshot['nextAction']['pending']));
        // Atendimento follows customers and partners only: a chat whose own contact is personal, automated or team gets no card.
        if ($conversation && in_array(self::ownClass($snapshot['participants'] ?? [], $classes), ['personal', 'automated', 'team'], true)) $verdict = 'none';
        $reason = is_string($card['reason'] ?? null) ? $card['reason'] : 'The analysis could not be read.';
        $card = in_array($verdict, Loops::QUIET, true) || !$card ? null : self::card($card, $loop, $reason);
        $this->verdicts->store((string) $snapshot['tenantId'], ...[...$key, $verdict, $reason, $card]);
        return ['status' => 'completed', 'requestId' => $result['requestId'] ?? null, 'cached' => false,
            'verdict' => $verdict, 'reason' => $reason, 'card' => $card];
    }

    /**
     * What the reader's card keeps of the answer. A card made from a loop shows that loop (`promise`), and the loop's
     * own text stands in for copy the model left out (an answer written while nothing was due yet).
     *
     * @param array<string, mixed> $card
     * @param ?array{id: ?string, side: string, text: string, dueAt: ?string} $loop
     * @return array<string, mixed>
     */
    private static function card(array $card, ?array $loop, string $reason): array
    {
        $card = array_diff_key($card, ['verdict' => true, 'reason' => true, 'participants' => true, 'record' => true, 'promise' => true]);
        if (!$loop) return $card;
        $card['promise'] = $loop;
        $card['headline'] = ($card['headline'] ?? null) ?: $loop['text'];
        $card['whyNow'] = ($card['whyNow'] ?? null) ?: $reason;
        return $card;
    }

    /** The class of the conversation's own contact or group, as labeled before or classified by this analysis. */
    private static function ownClass(array $participants, array $classes): ?string
    {
        foreach ($participants as $participant) {
            if (!empty($participant['own'])) return $classes[$participant['id']] ?? $participant['label'] ?? null;
        }
        return null;
    }

    /** The deck's analysis locale from its UI locale: Brazilian Portuguese or English. Verdicts are stored per locale. */
    public static function locale(mixed $value): string
    {
        return $value === 'pt_BR' || $value === 'pt' || $value === 'pt-BR' ? 'pt-BR' : 'en';
    }

    /**
     * Where each queued analysis stands, by request id, in one backend call: pending, completed, failed, limited, or
     * expired (unknown). It reads no evidence, so the deck's polls cost no snapshot (Batch::status).
     *
     * @param list<string> $requestIds
     * @return array<string, string>
     */
    public function statuses(array $requestIds): array
    {
        if (!$requestIds) return [];
        $data = $this->post('/internal/catch-up/summary/status', ['requestIds' => array_values(array_unique($requestIds))]);
        $statuses = is_array($data['statuses'] ?? null) ? $data['statuses'] : [];
        $result = [];
        foreach ($requestIds as $id) $result[$id] = is_string($statuses[$id] ?? null) ? $statuses[$id] : 'expired';
        return $result;
    }

    /** The backend enqueues/polls Hatchet. All evidence has just passed Feed's ACL checks. */
    public function request(array $snapshot, string $locale, ?string $requestId = null, ?array $notify = null): array
    {
        $instance = (string) $snapshot['catchUpPolicy']['instance'];
        if (!$instance) throw new ServiceUnavailable('Catch Up backend is not configured.');
        $payload = [
            'source' => [
                'type' => $snapshot['type'], 'instance' => $instance, 'tenantId' => (string) $snapshot['tenantId'],
                'userId' => $this->user->getId(), 'recordId' => $snapshot['id'],
            ],
            // With a record, only what came after it (and a few entries before) is read again.
            'facts' => (object) $snapshot['facts'], 'evidence' => Records::trim($snapshot['evidence'], $snapshot['record'] ?? null),
            'record' => $snapshot['record'] ?? null, 'locale' => $locale,
            // The account fields stay in the CRM; the backend gets who each participant is, nothing more.
            'participants' => array_map(fn ($participant) => array_intersect_key($participant, array_flip(['id', 'name', 'group', 'label', 'classify'])),
                $snapshot['participants'] ?? []),
            'policy' => $snapshot['catchUpPolicy'],
        ];
        if ($requestId !== null) $payload['requestId'] = $requestId;
        // Not part of the analysis: what the backend sends back when it finishes (Settle::complete).
        if ($notify !== null) $payload['notify'] = $notify;
        $data = $this->post('/internal/catch-up/summary', $payload);
        if (empty($data['status'])) throw new ServiceUnavailable('Invalid Catch Up backend response.');
        return array_intersect_key($data, array_flip(['status', 'requestId', 'summary', 'card', 'model']));
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $payload): array
    {
        $secret = getenv('CATCH_UP_BACKEND_SECRET');
        $baseUrl = getenv('CATCH_UP_BACKEND_URL');
        if (!$secret || !$baseUrl) throw new ServiceUnavailable('Catch Up backend is not configured.');
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $curl = curl_init(rtrim($baseUrl, '/') . $path);
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Crm-Timestamp: ' . $timestamp, 'X-Crm-Signature: sha256=' . $signature],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!in_array($status, [200, 202], true) || !is_string($response)) throw new ServiceUnavailable('Catch Up backend is temporarily unavailable.');
        $data = json_decode($response, true);
        if (!is_array($data)) throw new ServiceUnavailable('Invalid Catch Up backend response.');
        return $data;
    }
}
