<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiDataset;

use Espo\Modules\Global\Hook\ValidateTeamsTenantBase;
use Espo\ORM\Entity;

/**
 * Refuses assigning this Meta CAPI dataset to another workspace's teams.
 *
 * See ValidateTeamsTenantBase for why `teams` is the sensitive field here.
 */
class ValidateTeamsTenant extends ValidateTeamsTenantBase
{
    public static int $order = 10;

    protected function label(Entity $entity): string
    {
        return 'Meta CAPI dataset';
    }
}
