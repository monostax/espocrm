<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Entities;

use Espo\Core\ORM\Entity;

/**
 * MetaConversionEvent — one inbound Click-to-WhatsApp / Instagram conversion
 * (a Meta automatic_event such as Purchase or LeadSubmitted) received from
 * Chatwoot via the conversation's additional_attributes.ctwa.conversions[].
 *
 * This is an inbound, Meta-origin attribution record. It is NEVER dispatched
 * to the Conversions API directly. Reporting to Meta happens only when the
 * linked Opportunity changes stage (Hooks\Opportunity\SendCapiOnStageChange ->
 * CapiDispatcher::dispatch), which detects this conversion's ctwaClid/igSid +
 * sourceId and emits the business_messaging event. The outbound send is
 * recorded on the resulting MetaCapiEventLog (reachable via the Opportunity).
 *
 * Roles:
 *   1. Idempotency: unique on `wamid` so a re-synced conversation never
 *      double-creates.
 *   2. Attribution: stores the join keys (ctwaClid/igSid + sourceId) plus the
 *      resolved Contact, Opportunity and MetaCapiDataset.
 */
class MetaConversionEvent extends Entity
{
    public const ENTITY_TYPE = 'MetaConversionEvent';

    /** eventName used for arrival-only rows. */
    public const EVENT_CONTACT = 'Contact';

    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_INSTAGRAM = 'instagram';
}
