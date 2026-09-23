<?php

namespace Espo\Modules\Chatwoot\Reports;

class ModelUsagePerDay extends ModelUsageTotals
{
    protected function groupColumn(): ?string
    {
        return 'DAY:runAt';
    }
}
