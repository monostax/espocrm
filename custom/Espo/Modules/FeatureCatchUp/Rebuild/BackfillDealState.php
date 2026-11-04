<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCatchUp\Services\DealStates;

/** Fills Em dia's deal state for open deals that have none yet; the ChatwootMessage hook keeps it current after. */
class BackfillDealState implements RebuildAction
{
    public function __construct(private DealStates $states, private Log $log) {}

    public function process(): void
    {
        $filled = $this->states->backfill();
        if ($filled) $this->log->info('FeatureCatchUp: filled deal state for {count} open deals.', ['count' => $filled]);
    }
}
