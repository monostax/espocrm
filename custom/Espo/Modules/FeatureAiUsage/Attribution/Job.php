<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiUsage\Attribution;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

class Job implements JobDataLess
{
    public function __construct(private EntityManager $entityManager, private Reconciler $reconciler, private Log $log) {}

    public function run(): void
    {
        $count = $this->reconciler->reconcile($this->entityManager->getPDO());
        if ($count) {
            $this->log->info('AI usage attribution: repaired {count} conversation links.', ['count' => $count]);
        }
    }
}
