<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Hooks\TrackingLink;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingLink;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingSource;
use Espo\Modules\FeatureTrackingEvent\Services\TrackingEventPersister;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Save-time validation + normalization for TrackingLink. Runs at order 10,
 * after CascadeTeamsFromSource (8) and AssignTenantFromTeam (9), so the
 * tenant is already derived. Validation-by-BadRequest from an ORM BeforeSave
 * hook is the established pattern (Global ValidateStageFunnel,
 * ValidateSingleCrmSourcePerTenant).
 *
 * Enforces:
 *   - targetUrl is an absolute http(s) URL (the redirect endpoint will
 *     303/302 to it verbatim; no other schemes, no relative paths);
 *   - eventCode normalizes (lowercase/trim, empty -> link_clicked) and
 *     matches TrackingEventPersister::CODE_PATTERN;
 *   - trackingSource is set, exists, and is NOT kind=CRM (the internal
 *     channel never accepts HTTP-borne events — link clicks arrive over
 *     HTTP);
 *   - a tenant resolved (links without teams record nothing — fail early
 *     with an actionable message instead);
 *   - the source belongs to the same tenant (cross-tenant attribution
 *     would leak events into another tenant's ledger).
 *
 * Skips silent saves (system writes: counter bumps run as direct UPDATEs
 * anyway and never pass here).
 *
 * @implements BeforeSave<TrackingLink>
 */
class ValidateLink implements BeforeSave
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof TrackingLink) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        $this->validateTargetUrl($entity);
        $this->normalizeEventCode($entity);

        $tenantId = $entity->get('tenantId');

        if (!is_string($tenantId) || $tenantId === '') {
            throw new BadRequest(
                'Tracking Link must resolve to a tenant — assign a team (or pick a tracking source that has one).'
            );
        }

        $this->validateSource($entity, $tenantId);
    }

    /**
     * @throws BadRequest
     */
    private function validateTargetUrl(TrackingLink $entity): void
    {
        $url = $entity->get('targetUrl');

        if (!is_string($url) || $url === '') {
            throw new BadRequest('Target URL is required.');
        }

        // IRI -> URI: percent-encode non-ASCII (raw accents in wa.me text
        // params etc.). Normally already done by the AsciiUrl sanitizer /
        // target-url field view; repeated here for ORM-level writers and
        // because filter_var below is ASCII-only anyway.
        $encoded = preg_replace_callback(
            '/[^\x21-\x7E]/u',
            static fn (array $matches) => rawurlencode($matches[0]),
            trim($url),
        );

        $url = is_string($encoded) ? $encoded : trim($url);

        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        if (
            !in_array($scheme, ['http', 'https'], true) ||
            filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw new BadRequest('Target URL must be an absolute http(s) URL.');
        }

        $entity->set('targetUrl', $url);
    }

    /**
     * @throws BadRequest
     */
    private function normalizeEventCode(TrackingLink $entity): void
    {
        $raw = $entity->get('eventCode');

        $code = is_string($raw) ? strtolower(trim($raw)) : '';

        if ($code === '') {
            $code = TrackingLink::EVENT_CODE_DEFAULT;
        }

        if (preg_match(TrackingEventPersister::CODE_PATTERN, $code) !== 1) {
            throw new BadRequest(
                'Event Code must be lowercase letters, digits and underscores, starting with a letter (max 64 chars).'
            );
        }

        $entity->set('eventCode', $code);
    }

    /**
     * @throws BadRequest
     */
    private function validateSource(TrackingLink $entity, string $tenantId): void
    {
        $sourceId = $entity->get('trackingSourceId');

        if (!is_string($sourceId) || $sourceId === '') {
            throw new BadRequest('Tracking Source is required.');
        }

        $source = $this->entityManager->getEntityById(TrackingSource::ENTITY_TYPE, $sourceId);

        if (!$source instanceof TrackingSource) {
            throw new BadRequest('Tracking Source not found.');
        }

        if ($source->isInternalKind()) {
            throw new BadRequest(
                'Tracking Links cannot use a CRM (Internal) source — link clicks arrive over HTTP. Pick a Website (or other external) source.'
            );
        }

        $sourceTenantId = $source->get('tenantId');

        // Fail closed: the link's own tenant is already guaranteed non-empty by
        // the caller, so an unresolvable source tenant means same-tenancy cannot
        // be proven. Skipping the comparison let a source whose team maps to no
        // Tenant be attached to any tenant's link.
        if (!is_string($sourceTenantId) || $sourceTenantId === '') {
            throw new BadRequest(
                'Tracking Source does not resolve to a tenant — assign it a team that belongs to exactly one Tenant.'
            );
        }

        if ($sourceTenantId !== $tenantId) {
            throw new BadRequest(
                'Tracking Source belongs to a different tenant than this link\'s teams.'
            );
        }
    }
}
