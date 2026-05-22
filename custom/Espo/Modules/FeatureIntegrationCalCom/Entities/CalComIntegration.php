<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationCalCom\Entities;

use Espo\Core\ORM\Entity;

/**
 * CalComIntegration entity — one row per cal.com webhook endpoint configured.
 *
 * Each row owns:
 *   - a unique apiKey (URL slug for the webhook endpoint)
 *   - an HMAC signing secret (encrypted at rest)
 *   - optional link to a MetaCapiDataset (when set, bookings are forwarded to Meta CAPI)
 *   - a triggerEvent → Meta event_name mapping
 *
 * One tenant can have N integrations (e.g. one per cal.com workspace / event type).
 */
class CalComIntegration extends Entity
{
    public const ENTITY_TYPE = 'CalComIntegration';

    public const STATUS_ACCEPTED = 'Accepted';
    public const STATUS_SKIPPED = 'Skipped';
    public const STATUS_BAD_REQUEST = 'BadRequest';
    public const STATUS_UNAUTHORIZED = 'Unauthorized';
}
