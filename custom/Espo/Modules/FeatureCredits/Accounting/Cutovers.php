<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use LogicException;
use PDO;
use Throwable;

/** Internal, immutable cutover scheduling under the admission parent lock. */
final class Cutovers
{
    public function __construct(private EntityManager $entityManager, private Clock $clock) {}

    public function schedule(CutoverInput $input): array
    {
        $pdo = $this->entityManager->getPDO();
        if ($pdo->inTransaction() || !in_array($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql'], true) ||
            $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new LogicException('Cutover scheduling requires its own MySQL/PostgreSQL transaction.');
        }
        $pdo->beginTransaction();
        try {
            $query = $pdo->prepare('SELECT id FROM tenant WHERE id = ? AND deleted = FALSE FOR UPDATE');
            $query->execute([$input->tenantId]);
            if ($query->fetchColumn() !== $input->tenantId) throw new NotFound('Unknown accounting tenant.');
            $query = $pdo->prepare('SELECT * FROM tenant_credit_cutover WHERE tenant_id = ?');
            $query->execute([$input->tenantId]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if ($row && ($row['deleted'] || $row['input_hash'] !== $input->hash)) {
                throw new Conflict('Tenant cutover is immutable.');
            }
            if (!$row) {
                $now = $this->clock->now();
                if ($input->cutoverAt < $now) throw new Conflict('A new tenant cutover cannot be backdated.');
                $row = ['id' => RecordId::generate(), 'cutover_at' => $input->cutoverAt, 'created_at' => $now];
                $pdo->prepare('INSERT INTO tenant_credit_cutover (id, tenant_id, cutover_at, input_hash, evidence, created_at)
                    VALUES (?, ?, ?, ?, ?, ?)')->execute([
                        $row['id'], $input->tenantId, $input->cutoverAt, $input->hash, $input->evidenceJson, $now,
                    ]);
            }
            $pdo->commit();
            return ['id' => $row['id'], 'tenantId' => $input->tenantId, 'cutoverAt' => $row['cutover_at'], 'createdAt' => $row['created_at']];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
