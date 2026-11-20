<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeImmutable;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Classes\CardGone;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * A card a link points at (Avisos on WhatsApp: `?card=<lane>:<id>`) that is no longer in the reader's deck: what became
 * of it, so the deck says so instead of opening on another card. Only for a reader who may open the subject, as the deck
 * checks it; who acted is a teammate's name or the AI agent's.
 *
 * - `won` / `lost`: the deal closed; who moved it, from its stream.
 * - `snoozed`: this reader deferred it ("Adiar", "Me lembre mais tarde"), until `at`.
 * - `resolved`: someone acted on the card in Em dia (Acts) after the customer's last message.
 * - `replied`: the team or the AI agent answered after it, outside Em dia.
 * - `quiet`: nothing of the above; the analysis sees nothing to do now.
 * - `gone`: the deal or conversation no longer exists.
 */
class Linked
{
    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private User $user,
        private Workspace $workspace,
        private Feed $feed,
        private ServiceFeed $service,
        private Transcript $transcript,
        private Acts $acts,
    ) {}

    /** @return array<string, mixed> */
    public function read(Request $request): array
    {
        $lane = $request->getQueryParam('lane');
        $id = (string) $request->getQueryParam('id');
        if (!in_array($lane, ['deals', 'service'], true) || !preg_match('/^[a-zA-Z0-9_-]{1,36}$/D', $id)) throw new BadRequest('Invalid card.');
        ['tenant' => $tenant, 'account' => $account] = $this->workspace->resolve($request);
        $now = Workspace::now($request);
        if ($lane === 'deals') {
            $this->feed->authorize();
            $deal = $this->em->getEntityById('Opportunity', $id);
            if (!$deal) return ['state' => 'gone'];
            if ($deal->get('tenantId') !== $tenant->getId() || !$this->acl->checkEntityRead($deal)) throw new Forbidden();
            $conversations = $this->feed->conversations($deal);
            $subject = [
                'name' => ($this->acl->checkField('Opportunity', 'contact') ? $deal->get('contactName') : null) ?: $deal->get('name'),
                'deal' => $deal->get('name'),
                'conversation' => $this->feed->target($conversations),
            ];
            $status = $deal->get('status');
            if (in_array($status, ['Won', 'Lost'], true)) return [...$subject, 'state' => strtolower((string) $status), ...$this->closedBy($id)];
            return [...$subject, ...$this->outcome('Opportunity', $id, $conversations, $now, false)];
        }
        $this->service->authorize();
        try {
            $conversation = $this->service->conversation($id, $account);
        } catch (CardGone) {
            // A deal removed or lost for it closed the conversation's cycle (ConversationCycle); access was checked first.
            $conversation = $this->em->getEntityById('ChatwootConversation', $id);
        } catch (NotFound) {
            return ['state' => 'gone'];
        }
        if (!$conversation) return ['state' => 'gone'];
        $subject = [
            'name' => $conversation->get('contactDisplayName') ?: $conversation->get('name'),
            'deal' => null,
            'conversation' => [
                'conversationId' => (int) $conversation->get('chatwootConversationId'),
                'accountId' => (int) $account->get('chatwootAccountId'),
            ],
        ];
        return [...$subject, ...$this->outcome('ChatwootConversation', $id, [$id => $conversation], $now, true)];
    }

    /**
     * Resolved, replied, snoozed or quiet. An act in Em dia counts when it came after the customer last wrote; otherwise
     * the newest message from the business after it says who answered. Only then does this reader's own snooze explain it.
     *
     * @param array<string, Entity> $conversations
     * @return array<string, mixed>
     */
    private function outcome(string $type, string $id, array $conversations, DateTimeImmutable $now, bool $direct): array
    {
        try {
            $messages = $this->transcript->read($conversations, $now, $direct)['evidence'];
        } catch (Throwable) {
            // Chatwoot can't be read now: what Em dia recorded still answers.
            $messages = [];
        }
        $public = array_values(array_filter($messages, fn (array $entry) => ($entry['sentBy'] ?? null) !== 'internal'));
        $customer = max([0, ...array_map(fn (array $entry) => (int) $entry['ts'],
            array_filter($public, fn (array $entry) => $entry['sentBy'] === 'customer'))]);
        $act = $this->acts->last($type, $id);
        $actedAt = $act ? (int) strtotime($act['at'] . ' UTC') : 0;
        if ($act && $actedAt >= $customer) {
            return ['state' => 'resolved', 'action' => $act['action'], 'by' => $this->userName($act['userId']), 'at' => self::iso($actedAt)];
        }
        $reply = null;
        foreach ($public as $entry) {
            if ($entry['sentBy'] !== 'customer' && (int) $entry['ts'] > $customer && (!$reply || $entry['ts'] > $reply['ts'])) $reply = $entry;
        }
        if ($reply) {
            // `outside`: typed on the phone and mirrored in, with no sender.
            return ['state' => 'replied', 'by' => $reply['author'] ?? null, 'sentBy' => $reply['sentBy'], 'at' => self::iso((int) $reply['ts'])];
        }
        if ($until = $this->snoozedUntil($type, $id)) return ['state' => 'snoozed', 'at' => $until];
        return ['state' => 'quiet'];
    }

    /** When this reader's "Adiar" on the subject ends, while it still hides the card. */
    private function snoozedUntil(string $type, string $id): ?string
    {
        $query = $this->em->getPDO()->prepare('SELECT snoozed_until FROM catch_up_snooze WHERE deleted = 0 AND user_id = :user '
            . 'AND target_type = :type AND target_id = :id AND snoozed_until > UTC_TIMESTAMP() LIMIT 1');
        $query->execute(['user' => $this->user->getId(), 'type' => $type, 'id' => $id]);
        $until = $query->fetchColumn();
        return is_string($until) ? self::iso((int) strtotime($until . ' UTC')) : null;
    }

    /**
     * Who moved the deal to its closing stage: its newest stream update that changed the status field (the stage), which
     * carries the new value. A deal closed before its stream recorded it says only that it closed.
     *
     * @return array{by?: ?string, at?: string}
     */
    private function closedBy(string $id): array
    {
        $query = $this->em->getPDO()->prepare("SELECT created_by_id, created_at FROM note WHERE deleted = 0 AND type = 'Update' "
            . "AND parent_type = 'Opportunity' AND parent_id = :id AND JSON_EXTRACT(data, '$.value') IS NOT NULL ORDER BY created_at DESC LIMIT 1");
        $query->execute(['id' => $id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return [];
        return ['by' => $row['created_by_id'] ? $this->userName((string) $row['created_by_id']) : null, 'at' => self::iso((int) strtotime($row['created_at'] . ' UTC'))];
    }

    private function userName(string $id): ?string
    {
        $name = $this->em->getEntityById('User', $id)?->get('name');
        return is_string($name) && $name !== '' ? $name : null;
    }

    private static function iso(int $timestamp): string
    {
        return gmdate(DATE_ATOM, $timestamp);
    }
}
