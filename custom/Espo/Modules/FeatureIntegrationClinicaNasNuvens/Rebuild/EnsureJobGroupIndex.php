<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Database\Helper;
use Espo\Core\Utils\Log;

class EnsureJobGroupIndex implements RebuildAction
{
    public function __construct(
        private Helper $dbHelper,
        private Log $log,
    ) {}

    public function process(): void
    {
        $pdo = $this->dbHelper->getPDO();
        $isPg = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql';

        if ($isPg) {
            // No SHOW INDEX on PostgreSQL; IF NOT EXISTS makes the probe
            // unnecessary. Unquoted index names fold to lowercase, and
            // `group` is a reserved word — double-quote it.
            $this->log->info("EnsureJobGroupIndex: Ensuring IDX_GROUP on job(group, created_at).");

            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_group ON job (\"group\", created_at)");

            return;
        }

        $stmt = $pdo->query("SHOW INDEX FROM job WHERE Key_name = 'IDX_GROUP'");
        $exists = $stmt->fetch() !== false;

        if ($exists) {
            return;
        }

        $this->log->info("EnsureJobGroupIndex: Creating IDX_GROUP on job(group, created_at).");

        $pdo->exec("CREATE INDEX IDX_GROUP ON job (`group`, `created_at`)");
    }
}
