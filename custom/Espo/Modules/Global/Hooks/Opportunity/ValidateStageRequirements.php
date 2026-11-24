<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\Opportunity;

use Espo\Core\Exceptions\ConflictSilent;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\CustomField\AiWriters;
use Espo\Modules\Global\Tools\Opportunity\StageRequirements;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateStageRequirements implements BeforeSave
{
    // After tenant/stage synchronization, before stage timing and persistence.
    public static int $order = 85;

    public function __construct(
        private StageRequirements $requirements,
        private AiWriters $aiWriters,
        private User $user,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $entity->clear(StageRequirements::LEFT_EMPTY);
        try {
            $this->requirements->validate($entity, (bool) $options->get(StageRequirements::BOOKKEEPING));
        } catch (ConflictSilent $e) {
            // An AI agent's save is never refused for an empty required field: what it couldn't fill stays for a
            // person, and the save says which (user decision, 2026-11-24).
            $refusal = json_decode($e->getBody() ?? '', true);
            if (!is_array($refusal) || ($refusal['code'] ?? null) !== 'opportunityStageRequirements' ||
                !$this->aiWriters->isAi($this->user->getId())) {
                throw $e;
            }
            $entity->set(StageRequirements::LEFT_EMPTY, (object) StageRequirements::leaveForPeople($entity, $refusal));
        }
    }
}
