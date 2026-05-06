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

        $stmt = $pdo->query("SHOW INDEX FROM job WHERE Key_name = 'IDX_GROUP'");
        $exists = $stmt->fetch() !== false;

        if ($exists) {
            return;
        }

        $this->log->info("EnsureJobGroupIndex: Creating IDX_GROUP on job(group, created_at).");

        $pdo->exec("CREATE INDEX IDX_GROUP ON job (`group`, `created_at`)");
    }
}
