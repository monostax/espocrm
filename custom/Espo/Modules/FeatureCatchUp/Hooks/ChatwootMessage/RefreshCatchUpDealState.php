<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Hooks\ChatwootMessage;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCatchUp\Services\DealStates;
use Espo\ORM\Entity;

/** Keeps Em dia's per-deal state (who spoke last, last staff writer) current as messages sync in. */
class RefreshCatchUpDealState
{
    public static int $order = 50;

    public function __construct(private DealStates $states, private Log $log) {}

    /** @param array<string, mixed> $options */
    public function afterSave(Entity $entity, array $options): void
    {
        try {
            $this->states->forMessage($entity);
        } catch (\Throwable $e) {
            // Em dia's sort order must never break message sync.
            $this->log->warning('FeatureCatchUp: deal state refresh failed for message {id}: {error}', [
                'id' => $entity->getId(), 'error' => $e->getMessage(),
            ]);
        }
    }
}
