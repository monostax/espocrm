<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTaskRecurrence\Jobs;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureTaskRecurrence\Services\Recurrence;
use Espo\ORM\EntityManager;

class ProcessTaskRecurrences implements JobDataLess
{
    public function __construct(private EntityManager $entityManager, private Log $log) {}

    public function run(): void
    {
        $seriesList = $this->entityManager->getRDBRepository('TaskRecurrenceSeries')->where(['state' => 'Active'])->order('lastProcessedAt')->limit(0, 100)->find();
        foreach ($seriesList as $series) {
            try {
                $actor = $this->entityManager->getEntityById('User', $series->get('actorId'));
                if (!$actor || !$actor->get('isActive')) throw new \RuntimeException('The recurrence execution user is inactive.');
                // A fresh application binds the actor BEFORE resolving ORM, hooks and reminder savers.
                // ServiceFactory::createForUser alone does not rebind those shared dependencies.
                $application = new Application(new ApplicationParams(services: ['user' => $actor]));
                $application->getInjectableFactory()->create(Recurrence::class)->process($series->getId());
            } catch (\Throwable $e) {
                $this->log->error('ProcessTaskRecurrences ' . $series->getId() . ': ' . $e->getMessage());
                $this->entityManager->getTransactionManager()->run(function () use ($series, $e) {
                    $locked = $this->entityManager->getRDBRepository('TaskRecurrenceSeries')->select('id')->where(['id' => $series->getId()])->forUpdate()->findOne();
                    if (!$locked) return;
                    $fresh = $this->entityManager->getEntityById('TaskRecurrenceSeries', $series->getId());
                    $fresh->set(['lastError' => substr($e->getMessage(), 0, 4000), 'lastProcessedAt' => gmdate('Y-m-d H:i:s')]);
                    $this->entityManager->saveEntity($fresh);
                });
            }
        }
    }
}
