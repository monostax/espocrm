<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Entities;

use Espo\Core\ORM\Entity;

/**
 * TrackingEvent — high-volume, append-only ledger of every tracked interaction.
 *
 * Identity model (one of):
 *   - Identified: contactId is set; the event belongs to a known Contact.
 *   - Anonymous:  anonymousId is set (contactId null). When the visitor later
 *                 identifies themselves, AnonymousStitcher rewrites these
 *                 rows in bulk to point at the resolved Contact.
 *   - System:     neither is set (e.g. tenant-wide aggregates). Rare.
 *
 * The polymorphic `subject` link lets a single event additionally reference
 * a Lead, Opportunity, or other downstream entity (e.g. "Pricing Page View
 * during Opportunity O-123").
 *
 * Stored as high-cardinality, non-streamed, non-searchable. All payload
 * fields are readOnly — events are immutable from the UI; mutations only
 * happen via the ingestion controller or background stitcher.
 *
 * Tenant + teams are required and cascaded from TrackingEventType. They are
 * denormalized onto every row so per-tenant queries don't have to join
 * back through the type table.
 */
class TrackingEvent extends Entity
{
    public const ENTITY_TYPE = 'TrackingEvent';

    public const STATUS_RECEIVED = 'Received';
    public const STATUS_STITCHED = 'Stitched';
    public const STATUS_PROCESSED = 'Processed';
    public const STATUS_FAILED = 'Failed';
    public const STATUS_SKIPPED = 'Skipped';

    /** Event ingested through the trusted, HMAC-verified path (kind=Server/Other). */
    public const CHANNEL_SERVER = 'server';

    /** Event ingested through the public, unsigned path (kind=Website/Mobile). */
    public const CHANNEL_BROWSER = 'browser';
}
