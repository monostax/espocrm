<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Entities;

use Espo\Core\ORM\Entity;

/**
 * Structured answer row materialised from Meta's field_data[] at ingest time.
 *
 * One row per (event, field_data entry). Denormalises form/contact/opportunity
 * for fast bottomPanel rendering and analytics without joins through the event.
 *
 * @method ?string getFieldKey()
 * @method ?string getLabel()
 * @method ?string getValue()
 * @method ?array  getValueRaw()
 * @method ?bool   getWasMapped()
 * @method ?string getEventId()
 * @method ?string getQuestionId()
 * @method ?string getFormId()
 * @method ?string getContactId()
 * @method ?string getOpportunityId()
 */
class MetaLeadgenAnswer extends Entity
{
    public const ENTITY_TYPE = 'MetaLeadgenAnswer';
}
