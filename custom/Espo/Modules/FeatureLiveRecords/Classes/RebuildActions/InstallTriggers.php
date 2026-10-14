<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Classes\RebuildActions;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Metadata;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureLiveRecords\Persistence\TriggerSql;
use Espo\Modules\FeatureLiveRecords\Persistence\Observer;
use Espo\Modules\FeatureLiveRecords\Persistence\ChangePolicy;

final class InstallTriggers implements RebuildAction
{
    public function __construct(private EntityManager $em, private Metadata $metadata) {}

    public function process(): void
    {
        $pdo = $this->em->getPDO();
        $targets = [];
        foreach ($this->em->getMetadata()->getEntityTypeList() as $type) {
            if ($this->metadata->get(['entityDefs', $type, 'mutationObserverClassName']) !== Observer::class) continue;
            $orm = $this->em->getMetadata()->get($type);
            if (!isset($orm['attributes']['tenantId']) || !empty($orm['attributes']['tenantId']['notStorable'])) continue;
            $table = \Espo\Core\Utils\Util::toUnderScore($type);
            $targets[$table] = ['type' => $type,
                'versioned' => isset($orm['versionAttribute']) || isset($orm['concurrencyAttribute'])];
        }
        TriggerSql::removeObsolete($pdo, array_map(fn ($target) => $target['versioned'], $targets));
        foreach ($targets as $table => $target) {
            $type = $target['type'];
            foreach (TriggerSql::statements($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME), $type, $table,
                $target['versioned'], true, ChangePolicy::columns($pdo, $table)) as $statement) $pdo->exec($statement);
        }
    }
}
