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
 *   - a kind (Website / Server / Mobile / Other / CRM)
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
    public const KIND_SERVER = 'Server';
    public const KIND_MOBILE = 'Mobile';
    public const KIND_OTHER = 'Other';

    /**
     * Internal-only kind: created by the user (one per tenant, enforced by
     * ValidateSingleCrmSourcePerTenant) as the opt-in switch for events the
     * CRM emits about itself (e.g. Opportunity stage changes), recorded via
     * InternalEventRecorder. Blocked at the HTTP ingest endpoint entirely —
     * no signing secret or origins apply. No CRM source (or an inactive
     * one) means internal event tracking is off for the tenant.
     */
    public const KIND_CRM = 'CRM';

    public const STATUS_ACCEPTED = 'Accepted';
    public const STATUS_SKIPPED = 'Skipped';
    public const STATUS_BAD_REQUEST = 'BadRequest';
    public const STATUS_UNAUTHORIZED = 'Unauthorized';

    /** Kinds that ingest through the public, unsigned (browser/app) path. */
    private const PUBLIC_KINDS = [
        self::KIND_WEBSITE,
        self::KIND_MOBILE,
    ];

    /**
     * Whether this source ingests through the trusted path (HMAC signature
     * mandatory). Single source of truth for the kind→trust policy, used by
     * the ingester, the save-validation record hook and clientDefs mirrors.
     *
     * Trusted: Server, Other (server-to-server integrations).
     * Public:  Website, Mobile (clients that cannot keep a secret).
     * CRM:     neither — internal-only; the ingester refuses it over HTTP
     *          before any trust decision (isInternalKind()).
     */
    public function isTrustedKind(): bool
    {
        return !in_array($this->get('kind'), self::PUBLIC_KINDS, true);
    }

    /**
     * Whether this source is the in-process (CRM-internal) channel, which
     * must never accept events over the HTTP ingest endpoint.
     */
    public function isInternalKind(): bool
    {
        return $this->get('kind') === self::KIND_CRM;
    }
}
