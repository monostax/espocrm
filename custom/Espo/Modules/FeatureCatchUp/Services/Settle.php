<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeZone;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCatchUp\Classes\DeckRequest;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Analysis at settle (decided 2026-11-10; every conversation since 2026-11-11): a conversation is analysed once it has
 * been quiet for Feed::SETTLE_MINUTES, whoever wrote last, groups included, before anyone opens a deck. Its card is then
 * ready when the deck opens and the tab counts are the deck's totals. Time alone never asks for an analysis: the stored
 * loops and the clock decide when a promise comes due or a quiet customer needs a nudge (Verdicts::flipDue()). When Em dia
 * is turned on, the backend hands over the 30-day window the same way (`backfill`), outside the account's daily limit.
 *
 * The backend finds these conversations in Chatwoot (Hatchet `catch-up-settle`) and hands each one to start(). When the
 * analysis finishes, Hatchet calls complete(), which stores the verdict, so nothing polls.
 *
 * Each analysis runs as one reader, through the deck's own feeds, snapshots and Summary: every access rule, the daily
 * limit and the billing (the customer's credits, as for deck analyses) are the deck's own, and the verdict is stored
 * under the key the deck reads. The reader is the person the subject belongs to when there is one, otherwise a Chatwoot
 * administrator of the account, who may open every conversation. Readers who see the same messages share the verdict;
 * one who sees fewer gets their own analysis when their deck reaches it.
 */
class Settle
{
    /** Open deals of one conversation analysed at most: the newest. */
    private const MAX_DEALS = 3;
    /** Readers tried for one subject: its owner, then Chatwoot administrators. */
    private const MAX_READERS = 4;
    private const LANES = ['deals', 'service'];

    public function __construct(
        private EntityManager $em,
        private ActorScope $actors,
        private Log $log,
    ) {}

    /**
     * Queue the analysis of a conversation that just settled: its open deals (Negócios), or the conversation itself
     * when it has none (Atendimento).
     *
     * @return array{subjects: list<array{lane: string, id: string, status: string}>, skipped?: string}
     */
    public function start(int $chatwootAccountId, int $displayId, bool $backfill = false): array
    {
        if ($chatwootAccountId < 1 || $displayId < 1) throw new BadRequest('Invalid conversation.');
        $account = $this->account($chatwootAccountId);
        if (!$account) return ['subjects' => [], 'skipped' => 'account'];
        $tenant = $this->em->getEntityById('Tenant', (string) $account->get('tenantId'));
        if (!$tenant) return ['subjects' => [], 'skipped' => 'tenant'];
        $conversation = $this->em->getRDBRepository('ChatwootConversation')
            ->where(['chatwootAccountId' => $account->getId(), 'chatwootConversationId' => $displayId])->findOne();
        // The CRM copies conversations every minute; one it doesn't have yet is analysed when a deck reaches it.
        if (!$conversation) return ['subjects' => [], 'skipped' => 'conversation'];

        $deals = $this->openDeals($conversation->getId());
        $subjects = $deals ? array_map(fn (string $id) => ['deals', $id], $deals) : [['service', $conversation->getId()]];
        $context = [
            'accountId' => (string) $chatwootAccountId,
            'timeZone' => self::timeZone($tenant),
            'locale' => self::locale($tenant),
        ];
        $result = [];
        foreach ($subjects as [$lane, $id]) {
            $readers = $this->readers($lane, $id, $conversation->getId(), $account->getId());
            $result[] = ['lane' => $lane, 'id' => $id, ...$this->queue($lane, $id, $readers, $context, $backfill)];
        }
        return ['subjects' => $result];
    }

    /**
     * The analysis start() queued has finished: as the same reader, build the snapshot again (access is checked again,
     * and a conversation that moved meanwhile answers `superseded`) and store the verdict.
     *
     * @return array{status: string, verdict?: ?string}
     */
    public function complete(string $lane, string $id, string $userId, int $chatwootAccountId, string $timeZone, string $locale, string $requestId): array
    {
        if (!in_array($lane, self::LANES, true) || !preg_match('/^[a-zA-Z0-9_-]{1,36}$/D', $id) || !preg_match('/^[a-zA-Z0-9_-]{1,36}$/D', $userId)
            || $chatwootAccountId < 1 || !in_array($locale, ['pt-BR', 'en'], true) || !preg_match('/^[a-f0-9]{64}$/D', $requestId)
            || !in_array($timeZone, DateTimeZone::listIdentifiers(), true)) {
            throw new BadRequest('Invalid completion.');
        }
        $context = ['accountId' => (string) $chatwootAccountId, 'timeZone' => $timeZone, 'locale' => $locale];
        try {
            $answer = $this->actors->run($userId, fn (callable $make) => $this->summary($make, $lane, $id, $context, $requestId, null));
        } catch (Forbidden|NotFound) {
            // The reader can no longer open it: nothing is stored; a deck that can will analyse it.
            return ['status' => 'unreadable'];
        }
        return ['status' => (string) ($answer['status'] ?? ''), 'verdict' => $answer['verdict'] ?? null];
    }

    /**
     * @param list<string> $readers
     * @param array{accountId: string, timeZone: string, locale: string} $context
     * @return array{status: string, cached?: bool, reader?: string}
     */
    private function queue(string $lane, string $id, array $readers, array $context, bool $backfill = false): array
    {
        $notify = ['lane' => $lane, 'id' => $id, 'accountId' => $context['accountId'], 'timeZone' => $context['timeZone']];
        if ($backfill) $notify['backfill'] = true;
        foreach ($readers as $reader) {
            try {
                $answer = $this->actors->run($reader, fn (callable $make) => $this->summary($make, $lane, $id, $context, null, $notify));
            } catch (Forbidden|NotFound) {
                // This reader may not open it (inactive, Chatwoot inbox rules, CRM ACL); the next one may.
                continue;
            }
            return ['status' => (string) ($answer['status'] ?? ''), 'cached' => (bool) ($answer['cached'] ?? false), 'reader' => $reader];
        }
        $this->log->info("CatchUp settle: no reader may open {$lane} {$id}.");
        return ['status' => 'unreadable'];
    }

    /**
     * @param callable(class-string): object $make
     * @param array{accountId: string, timeZone: string, locale: string} $context
     */
    private function summary(callable $make, string $lane, string $id, array $context, ?string $requestId, ?array $notify): array
    {
        $request = new DeckRequest(['accountId' => $context['accountId'], 'timeZone' => $context['timeZone']]);
        $snapshot = $lane === 'service' ? $make(ServiceFeed::class)->snapshot($id, $request) : $make(Feed::class)->snapshot($id, $request);
        return $make(Summary::class)->analyse($snapshot, $context['locale'], $requestId, $notify);
    }

    /**
     * Who the analysis runs as: the subject's person (the deal's owner, or the conversation's Chatwoot assignee, when a
     * person and not the AI), then the account's Chatwoot administrators who are people, oldest first.
     *
     * @return list<string>
     */
    private function readers(string $lane, string $id, string $conversationId, string $accountId): array
    {
        $pdo = $this->em->getPDO();
        if ($lane === 'deals') {
            $query = $pdo->prepare('SELECT o.assigned_user_id FROM opportunity o WHERE o.id = :id AND o.deleted = 0 '
                . 'AND o.assigned_user_id NOT IN (' . Ownership::AI_USERS_SQL . ')');
            $query->execute(['id' => $id]);
        } else {
            $query = $pdo->prepare('SELECT ' . Ownership::CONVERSATION_PERSON_SQL . ' FROM chatwoot_conversation c '
                . 'JOIN chatwoot_account a ON a.id = c.chatwoot_account_id WHERE c.id = :id');
            $query->execute(['id' => $conversationId]);
        }
        $owner = $query->fetchColumn();
        $admins = $pdo->prepare('SELECT cu.assigned_user_id FROM chatwoot_account_user_membership m '
            . 'JOIN chatwoot_user cu ON cu.id = m.chatwoot_user_id AND cu.deleted = 0 '
            . 'JOIN user u ON u.id = cu.assigned_user_id AND u.deleted = 0 AND u.is_active = 1 '
            . "WHERE m.deleted = 0 AND m.chatwoot_account_id = :account AND m.role = 'administrator' AND m.is_a_i = 0 "
            . 'ORDER BY m.created_at, m.id LIMIT ' . self::MAX_READERS);
        $admins->execute(['account' => $accountId]);
        $readers = array_map('strval', array_filter([$owner ?: null, ...$admins->fetchAll(\PDO::FETCH_COLUMN)]));
        return array_slice(array_values(array_unique($readers)), 0, self::MAX_READERS);
    }

    /** @return list<string> the conversation's open deals, newest first */
    private function openDeals(string $conversationId): array
    {
        $query = $this->em->getPDO()->prepare('SELECT o.id FROM chatwoot_conversation_opportunity r '
            . "JOIN opportunity o ON o.id = r.opportunity_id AND o.deleted = 0 AND o.status NOT IN ('Won','Lost') "
            . 'WHERE r.chatwoot_conversation_id = :id AND r.deleted = 0 ORDER BY o.created_at DESC, o.id LIMIT ' . self::MAX_DEALS);
        $query->execute(['id' => $conversationId]);
        return array_map('strval', $query->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function account(int $chatwootAccountId): ?Entity
    {
        $accounts = iterator_to_array($this->em->getRDBRepository('ChatwootAccount')
            ->where(['chatwootAccountId' => $chatwootAccountId, 'status' => 'active'])->limit(0, 2)->find());
        return count($accounts) === 1 ? reset($accounts) : null;
    }

    /**
     * The workspace's language for the analysis. Verdicts are stored per locale and the deck asks in its UI's; every
     * workspace today is Brazilian, so only one set explicitly to English reads in English.
     */
    public static function locale(Entity $tenant): string
    {
        return str_starts_with((string) $tenant->get('language'), 'en') ? 'en' : 'pt-BR';
    }

    public static function timeZone(Entity $tenant): string
    {
        $zone = (string) ($tenant->get('timeZone') ?: '');
        return in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'America/Sao_Paulo';
    }
}
