<?php

namespace tests\unit\Espo\Modules\FeatureCredits\fixtures;

use Espo\Modules\FeatureCredits\Jobs\AdmissionJobs;
use PDO;

class RecordingAdmissionJobs extends AdmissionJobs
{
    public array $queued = [];

    public function __construct(private PDO $pdo) {}

    public function verification(string $tenant, string $id): void
    {
        $this->queued[] = ['verification', $tenant, $id, $this->pdo->inTransaction()];
    }

    public function enrichment(string $tenant, string $id): void
    {
        $this->queued[] = ['enrichment', $tenant, $id, $this->pdo->inTransaction()];
    }
}
