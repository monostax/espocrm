<?php

namespace Espo\Modules\Chatwoot\Reports;

class ModelUsageByModel extends ModelUsageTotals
{
    protected function groupColumn(): ?string
    {
        return 'model';
    }
}
