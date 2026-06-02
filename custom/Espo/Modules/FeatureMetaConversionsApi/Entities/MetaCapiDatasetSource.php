<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Entities;

use Espo\Core\ORM\Entity;

/**
 * MetaCapiDatasetSource entity — junction binding a MetaCapiDataset to a Meta
 * messaging source (a WhatsApp Business Account or an Instagram business
 * account).
 *
 * The underlying WhatsAppBusinessAccount is a virtual entity (no DB table), so
 * the source is stored here as plain identifiers (channel + sourceId +
 * sourceName) plus the OAuthAccount whose token authorizes it. One
 * MetaCapiDataset has many of these rows; each (channel, sourceId) pair is
 * unique, giving an unambiguous reverse lookup for the DatasetResolver.
 *
 * sourceId maps to the Conversions API user_data field:
 *   - whatsapp  -> whatsapp_business_account_id
 *   - instagram -> instagram_business_account_id
 */
class MetaCapiDatasetSource extends Entity
{
    public const ENTITY_TYPE = 'MetaCapiDatasetSource';

    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_INSTAGRAM = 'instagram';
}
