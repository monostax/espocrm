<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCatchUp\Services\ConversationStates;

/** Fills the last staff writer for recent conversations that have no row; the ChatwootMessage hook keeps it current after. */
class BackfillConversationState implements RebuildAction
{
    public function __construct(private ConversationStates $states, private Log $log) {}

    public function process(): void
    {
        $filled = $this->states->backfill();
        if ($filled) $this->log->info('FeatureCatchUp: filled conversation state for {count} recent conversations.', ['count' => $filled]);
    }
}
