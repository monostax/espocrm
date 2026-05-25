<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Entities;

use Espo\Core\ORM\Entity;

/**
 * Mirror of a single question in a Meta Lead Ads form schema.
 *
 * Synced at form-sync time from
 *   GET /{form-id}?fields=questions{key,label,type,input,options}
 *
 * Provides display labels and option dictionaries used to render
 * MetaLeadgenAnswer values in a human-friendly form.
 *
 * @method ?string   getKey()
 * @method ?string   getLabel()
 * @method ?string   getType()
 * @method ?string   getInputType()
 * @method ?array    getOptions()
 * @method ?int      getOrder()
 * @method ?bool     getIsStandard()
 * @method ?string   getContactAttribute()
 * @method ?bool     getIsActive()
 * @method ?string   getFormId()
 */
class MetaLeadFormQuestion extends Entity
{
    public const ENTITY_TYPE = 'MetaLeadFormQuestion';
}
