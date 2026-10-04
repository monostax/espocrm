<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Modules\FeatureInitiative\Hooks\Initiative\ValidateProgress;
use Espo\ORM\EntityManager;

class BackfillStageCategories implements RebuildAction
{
    public function __construct(private EntityManager $entityManager) {}

    public function process(): void
    {
        // Old per-initiative progress cannot determine a shared stage's category.
        $builder = $this->entityManager->getQueryBuilder();
        $executor = $this->entityManager->getQueryExecutor();
        $executor->execute($builder->update()->in('InitiativeStage')->set(['category' => 'Open'])
            ->where(['OR' => [['category' => null], ['category' => '']]])->build());

        foreach (ValidateProgress::STATUSES as $category) {
            $stages = $builder->select()->from('InitiativeStage')->select('id')
                ->where(['category' => $category, 'deleted' => false])->build();
            $executor->execute($builder->update()->in('Initiative')->set(['status' => $category])
                ->where([
                    'stageId=s' => $stages,
                    'deleted' => false,
                    'OR' => [['status' => null], ['status!=' => $category]],
                ])->build());
        }
    }
}
