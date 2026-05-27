<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationCalCom\Entities;

use Espo\Core\ORM\Entity;

/**
 * CalComIntegration entity — one row per cal.com webhook endpoint configured.
 *
 * The entity id IS the webhook slug: cal.com posts to
 * /api/v1/CalCom/receive/{id}.
 *
 * Each row owns:
 *   - an HMAC signing secret (encrypted at rest)
 *   - per-tenant scoping derived from the selected teams
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
