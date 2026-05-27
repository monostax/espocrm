<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Entities;

use Espo\Core\ORM\Entity;
use stdClass;

/**
 * @method ?string   getLeadgenId()
 * @method ?string   getFormId()        Meta numeric form id (scalar).
 * @method ?string   getMetaPageId()    Meta numeric page id (scalar).
 * @method ?string   getAdId()
 * @method ?string   getStatus()
 * @method ?string   getErrorMessage()
 * @method ?stdClass getRawPayload()
 * @method ?string   getLeadFormId()    CRM id of linked MetaLeadForm (link `leadForm` foreign-id).
 * @method ?string   getPageId()        CRM id of linked MetaFacebookPage (link `page` foreign-id).
 * @method ?string   getContactId()
 * @method ?string   getOpportunityId()
 */
class MetaLeadgenEvent extends Entity
{
    public const ENTITY_TYPE = 'MetaLeadgenEvent';

    public const STATUS_RECEIVED  = 'Received';
    public const STATUS_PROCESSED = 'Processed';
    public const STATUS_FAILED    = 'Failed';
    public const STATUS_SKIPPED   = 'Skipped';
}
