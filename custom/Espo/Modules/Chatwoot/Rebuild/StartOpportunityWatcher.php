<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Modules\Chatwoot\Services\OpportunityWatcher;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\UpdateBuilder;

/**
 * The AI watcher is on by default for every opportunity created from its release on, and off for
 * the ones that already existed. The new column arrives with the default (on) in every row, so the
 * first rebuild that knows the watcher switches the existing rows off, once: the config key keeps
 * later rebuilds from touching deals created since, or deals a person switched on.
 */
class StartOpportunityWatcher implements RebuildAction
{
    public function __construct(
        private Config $config,
        private ConfigWriter $configWriter,
        private EntityManager $entityManager,
    ) {}

    public function process(): void
    {
        if ($this->config->get(OpportunityWatcher::STARTED_AT)) {
            return;
        }
        $this->entityManager->getQueryExecutor()->execute(
            UpdateBuilder::create()
                ->in('Opportunity')
                ->set(['aiWatchEnabled' => false])
                ->where(['aiWatchEnabled' => true])
                ->build()
        );
        $this->configWriter->set(OpportunityWatcher::STARTED_AT, gmdate('Y-m-d H:i:s'));
        $this->configWriter->save();
    }
}
