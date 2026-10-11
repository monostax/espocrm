<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use Throwable;

/** Bounded posted-ledger read model; no source records or private evidence are expanded. */
final class TransactionHistory
{
    public function __construct(private EntityManager $entityManager, private Clock $clock) {}

    public function inspect(TransactionHistoryQuery $input): array
    {
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction()) {
            throw new LogicException('Transaction history must own its read-only transaction.');
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'pgsql'], true) ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new LogicException('Transaction history requires MySQL/PostgreSQL PDO with exception mode.');
        }
        if ($driver === 'mysql') {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }
        $pdo->beginTransaction();
        try {
            if ($driver === 'pgsql') {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $query = $pdo->prepare('SELECT id FROM tenant WHERE id = ? AND deleted = FALSE');
            $query->execute([$input->tenantId]);
            if ($query->fetchColumn() === false) {
                throw new NotFound('Unknown accounting tenant.');
            }
            $now = $this->clock->now();
            $params = [$input->tenantId];
            $where = '';
            if ($input->beforeId !== null) {
                $where = ' AND (posted_at < ? OR (posted_at = ? AND id < ?))';
                array_push($params, $input->beforePostedAt, $input->beforePostedAt, $input->beforeId);
            }
            // Read deleted postings as well: hiding an invalid ledger row would misrepresent history.
            $query = $pdo->prepare('SELECT id, type, credits, occurred_at, posted_at, deleted
                FROM credit_transaction WHERE tenant_id = ?' . $where .
                ' ORDER BY posted_at DESC, id DESC LIMIT ' . ($input->limit + 1));
            $query->execute($params);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            $hasMore = count($rows) > $input->limit;
            $list = [];
            foreach ($rows as $index => $row) {
                if ($row['deleted'] || !in_array($row['type'], ['grant', 'debit', 'expiration', 'reversal',
                    NativeSearchDebt::DEBIT, NativeSearchDebt::PAYMENT, NativeSearchDebt::RECOVERY], true)) {
                    throw new LogicException('Invalid posted transaction history.');
                }
                if ($index === $input->limit) {
                    break;
                }
                $list[] = ['id' => $row['id'], 'type' => $row['type'],
                    'credits' => (string) Amount::fromString($row['credits']),
                    'occurredAt' => $row['occurred_at'], 'postedAt' => $row['posted_at']];
            }
            $last = $list ? $list[count($list) - 1] : null;
            $next = $hasMore ? TransactionHistoryQuery::cursor($input->tenantId, $last['postedAt'], $last['id']) : null;
            $pdo->commit();
            return ['tenantId' => $input->tenantId, 'observedAt' => $now, 'list' => $list, 'nextCursor' => $next];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
