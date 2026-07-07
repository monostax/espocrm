<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingLink;
use Espo\Modules\FeatureTrackingEvent\Services\TrackingLinkRedirector;
use Espo\ORM\EntityManager;
use RuntimeException;
use stdClass;

/**
 * Authenticated minting of per-recipient short URLs (Resources/routes.json):
 *
 *   POST /TrackingLink/mintContactUrl
 *   body: { "id": "{trackingLinkId}", "contactId": "{contactId}",
 *           "ttlDays": 90 }                       // ttlDays optional
 *   response: { "url": "https://.../{slug}?c={token}" }
 *
 * The returned URL carries a ContactToken so the click lands already
 * identified AND the landing page session identifies via the forwarded
 * `mstx_c` param (which stitches the recipient's prior anonymous history).
 * Intended callers: message-sending automations (email/WhatsApp templates)
 * and manual copy-paste from integrations.
 *
 * ACL: read access to both the link and the contact is required; the
 * contact must belong to the link's tenant (the token would be inert
 * cross-tenant anyway — fail loudly at mint time instead of silently at
 * click time).
 */
class TrackingLinkMint
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private TrackingLinkRedirector $redirector,
    ) {}

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function postActionMintContactUrl(Request $request): stdClass
    {
        $data = $request->getParsedBody();

        $linkId = $data->id ?? null;
        $contactId = $data->contactId ?? null;
        $ttlDays = $data->ttlDays ?? null;

        if (!is_string($linkId) || $linkId === '' || !is_string($contactId) || $contactId === '') {
            throw new BadRequest('id and contactId are required.');
        }

        $link = $this->entityManager->getEntityById(TrackingLink::ENTITY_TYPE, $linkId);

        if (!$link instanceof TrackingLink) {
            throw new NotFound('Tracking Link not found.');
        }

        if (!$this->acl->checkEntityRead($link)) {
            throw new Forbidden();
        }

        $contact = $this->entityManager->getEntityById('Contact', $contactId);

        if ($contact === null) {
            throw new NotFound('Contact not found.');
        }

        if (!$this->acl->checkEntityRead($contact)) {
            throw new Forbidden();
        }

        $linkTenantId = $link->get('tenantId');
        $contactTenantId = $contact->get('tenantId');

        if (
            !is_string($linkTenantId) || $linkTenantId === '' ||
            $contactTenantId !== $linkTenantId
        ) {
            throw new BadRequest('Contact does not belong to this link\'s tenant.');
        }

        try {
            $url = $this->redirector->buildContactUrl(
                $link,
                $contactId,
                is_numeric($ttlDays) ? (int) $ttlDays : null,
            );
        } catch (RuntimeException $e) {
            throw new Error($e->getMessage());
        }

        return (object) ['url' => $url];
    }
}
