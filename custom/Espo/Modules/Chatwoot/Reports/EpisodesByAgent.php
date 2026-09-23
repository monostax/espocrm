<?php

namespace Espo\Modules\Chatwoot\Reports;

class EpisodesByAgent extends AbstractEpisodeParticipation
{
    protected function namesField(): string
    {
        return 'lifecycleAssigneeNames';
    }

    protected function groupField(): string
    {
        return 'lifecycleAssignees';
    }
}
