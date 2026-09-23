<?php

namespace Espo\Modules\Chatwoot\Reports;

class EpisodesByTeam extends AbstractEpisodeParticipation
{
    protected function namesField(): string
    {
        return 'lifecycleTeamNames';
    }

    protected function groupField(): string
    {
        return 'lifecycleTeams';
    }
}
