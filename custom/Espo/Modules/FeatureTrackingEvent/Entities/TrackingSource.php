<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Entities;

use Espo\Core\ORM\Entity;

/**
 * TrackingSource — a per-tenant ingestion channel with its own HMAC secret.
 *
 * The entity id IS the public ingestion slug: external systems POST to
 * /api/v1/TrackingEvent/receive/{sourceId}. Each source owns:
 *   - a signingSecret (encrypted at rest, used for HMAC-SHA256 verification)
 *   - a kind (Website / Chatwoot / Server / Mobile / Other)
 *   - optional CORS allow-list for browser-side ingestion
 *   - per-tenant scoping derived from selected teams
 *   - allowUnknownEventCode: if true, unknown TrackingEventType codes are
 *     auto-created on first sight (handy for greenfield rollout; turn off
 *     once your dictionary stabilises)
 *
 * One tenant can have N sources (e.g. one per environment, or one per
 * website domain, or one per server-side integration). Rotating a secret
 * is just an edit on the source row.
 */
class TrackingSource extends Entity
{
    public const ENTITY_TYPE = 'TrackingSource';

    public const KIND_WEBSITE = 'Website';
    public const KIND_CHATWOOT = 'Chatwoot';
    public const KIND_SERVER = 'Server';
    public const KIND_MOBILE = 'Mobile';
    public const KIND_OTHER = 'Other';

    public const STATUS_ACCEPTED = 'Accepted';
    public const STATUS_SKIPPED = 'Skipped';
    public const STATUS_BAD_REQUEST = 'BadRequest';
    public const STATUS_UNAUTHORIZED = 'Unauthorized';
}
