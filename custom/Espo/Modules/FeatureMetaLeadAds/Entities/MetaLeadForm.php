<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Entities;

use Espo\Core\ORM\Entity;
use stdClass;

/**
 * @method ?string   getName()
 * @method ?string   getFormId()
 * @method ?string   getPageId()
 * @method ?string   getFunnelId()
 * @method ?string   getOpportunityStageId()
 * @method ?string   getAssignedUserId()
 * @method ?bool     getIsActive()
 * @method ?bool     getCreateOpportunity()
 * @method ?stdClass getFieldMapping()
 */
class MetaLeadForm extends Entity
{
    public const ENTITY_TYPE = 'MetaLeadForm';
}
