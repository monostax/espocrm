<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Entities;

use Espo\Core\ORM\Entity;

/**
 * TrackingEventType — per-tenant dictionary defining what can be tracked.
 *
 * Tenants create one row per logical event (e.g. "Pricing Page View",
 * "Form Submit", "WhatsApp Ad Click"). Each row has a stable `code` that
 * ingestion payloads reference, so external systems don't need to know
 * EspoCRM entity ids.
 *
 * Uniqueness: (code, tenantId) — same code may exist in different tenants.
 *
 * Categories are a small hard-coded enum so cross-tenant reporting is
 * possible (e.g. "show me every WebTraffic event for the last 7 days"),
 * while the free-form `name` and `code` give tenants full flexibility.
 */
class TrackingEventType extends Entity
{
    public const ENTITY_TYPE = 'TrackingEventType';

    public const CATEGORY_WEB_TRAFFIC = 'WebTraffic';
    public const CATEGORY_INBOUND_MESSAGE = 'InboundMessage';
    public const CATEGORY_FORM_SUBMIT = 'FormSubmit';
    public const CATEGORY_CONVERSION = 'Conversion';
    public const CATEGORY_SYSTEM = 'System';
    public const CATEGORY_CUSTOM = 'Custom';
}
