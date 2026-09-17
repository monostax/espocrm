<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\DateTime\Clock;
use Espo\Modules\Global\Tools\Opportunity\StageTiming;
use Espo\ORM\EntityManager;
use DateTimeZone;

class InitializeOpportunityStageTiming implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private StageTiming $timing,
        private Clock $clock,
        private Config $config,
        private ConfigWriter $configWriter,
    ) {}

    public function process(): void
    {
        if ($this->config->get('opportunityStageTimingInitialized')) {
            return;
        }

        $cutoff = $this->config->get('opportunityStageTimingActivationTime');
        if (!$cutoff) {
            $cutoff = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $this->configWriter->set('opportunityStageTimingActivationTime', $cutoff);
            $this->configWriter->save();
        }

        $afterId = '';
        do {
            $batch = $this->entityManager->getRDBRepository('Opportunity')
                ->where(['id>' => $afterId, 'stageTrackingStartedAt' => null, 'createdAt<=' => $cutoff])
                ->order('id')->limit(0, 100)->find();
            foreach ($batch as $opportunity) {
                $afterId = $opportunity->getId();
                $this->timing->initialize($afterId, $cutoff);
            }
        } while (count($batch) === 100);

        $this->configWriter->set('opportunityStageTimingInitialized', true);
        $this->configWriter->save();
    }
}
