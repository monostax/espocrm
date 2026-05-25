<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationCalCom\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Contact;
use Espo\Modules\FeatureIntegrationCalCom\Entities\CalComIntegration;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Finds or creates a Contact based on a cal.com attendee.
 *
 * Tenant scoping (multi-tenant safety):
 *   - All lookups are SCOPED to the integration's tenant. In shared-DB
 *     multi-tenant deployments, two tenants can legitimately own Contacts
 *     with the same email. A globally-scoped lookup would silently merge
 *     Tenant B's booking into Tenant A's Contact, stamping cal.com
 *     attribution onto the wrong tenant's record and triggering CAPI
 *     dispatch under the wrong Pixel.
 *   - Existing Contact augmentation has a defense-in-depth cross-tenant
 *     guard (should never fire after the scoped lookup, but logs an error
 *     if it does, in case a future code path bypasses the filter).
 *   - Newly-created Contacts inherit teamsIds + tenantId from the
 *     integration so Contact-required-tenant validation passes and the
 *     row is correctly team-ACL-scoped.
 *
 * Match strategy:
 *   1. By emailAddress (canonical key — Meta also uses email as primary
 *      user_data field), filtered by integration.tenantId.
 *   2. If multiple matches exist, pick the most recently modified.
 *   3. If none and {@see $createIfMissing} is true, create a new Contact
 *      pre-stamped with the integration's teams/tenant.
 *
 * Newly created Contacts get:
 *   - firstName, lastName, emailAddress, phoneNumber (from booking)
 *   - source = "Cal.com"  (free-text; harmless if the field doesn't exist on the tenant)
 *   - metaFbc, metaFbp (if present in booking)  -> drives match quality
 *   - metaCapturedAt = now
 *   - teamsIds, tenantId  <- inherited from the CalComIntegration
 *
 * Existing Contacts are augmented (never overwritten) with missing
 * metaFbc/metaFbp/phone — only when the Contact's tenant matches the
 * integration's tenant.
 */
class CalComContactMatcher
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function matchOrCreate(
        ParsedCalComBooking $booking,
        bool $createIfMissing,
        CalComIntegration $integration,
    ): ?Contact {
        $integrationTenantId = (string) ($integration->get('tenantId') ?? '');

        if ($integrationTenantId === '') {
            $this->log->warning(sprintf(
                'CalCom: integration %s has no tenantId; refusing Contact match/create to avoid tenant-blind dedup. ' .
                'Assign teams on the CalComIntegration so AssignTenantFromTeam can derive a tenant.',
                (string) $integration->getId(),
            ));

            return null;
        }

        $contact = $this->findByEmail($booking->email, $integrationTenantId);

        if ($contact) {
            $this->augmentExisting($contact, $booking, $integrationTenantId);

            return $contact;
        }

        if (!$createIfMissing) {
            $this->log->info(sprintf(
                'MetaCapi cal.com: no Contact found for email=%s (tenant=%s) and createIfMissing=false; skipping.',
                $booking->email,
                $integrationTenantId,
            ));

            return null;
        }

        return $this->createNew($booking, $integration);
    }

    /**
     * Tenant-scoped Contact lookup.
     */
    private function findByEmail(string $email, string $tenantId): ?Contact
    {
        $contact = $this->entityManager
            ->getRDBRepository(Contact::ENTITY_TYPE)
            ->where([
                'emailAddress' => $email,
                'tenantId'     => $tenantId,
                'deleted'      => false,
            ])
            ->order('modifiedAt', 'DESC')
            ->findOne();

        return $contact instanceof Contact ? $contact : null;
    }

    private function augmentExisting(
        Contact $contact,
        ParsedCalComBooking $booking,
        string $integrationTenantId,
    ): void {
        // Defense in depth: findByEmail already filters by tenant, so this
        // should never trip. But if a future code path bypasses the filter,
        // refuse to write cal.com attribution onto a Contact owned by a
        // different tenant.
        $contactTenantId = (string) ($contact->get('tenantId') ?? '');

        if ($contactTenantId !== '' && $contactTenantId !== $integrationTenantId) {
            $this->log->error(sprintf(
                'CalCom: cross-tenant augment refused — Contact %s (tenant=%s) vs integration tenant=%s.',
                (string) $contact->getId(),
                $contactTenantId,
                $integrationTenantId,
            ));

            return;
        }

        $changed = false;

        if (!$contact->get('phoneNumber') && $booking->phone) {
            $contact->set('phoneNumber', $booking->phone);
            $changed = true;
        }

        if (!$contact->get('metaFbc') && $booking->fbc) {
            $contact->set('metaFbc', $booking->fbc);
            $changed = true;
        }

        if (!$contact->get('metaFbp') && $booking->fbp) {
            $contact->set('metaFbp', $booking->fbp);
            $changed = true;
        }

        if ($changed) {
            try {
                $this->entityManager->saveEntity($contact, ['skipHooks' => true, 'silent' => true]);
            } catch (Throwable $e) {
                $this->log->warning(
                    'MetaCapi cal.com: failed to augment existing Contact: ' . $e->getMessage(),
                );
            }
        }
    }

    private function createNew(ParsedCalComBooking $booking, CalComIntegration $integration): ?Contact
    {
        try {
            /** @var Contact $contact */
            $contact = $this->entityManager->getNewEntity(Contact::ENTITY_TYPE);

            if ($booking->firstName) {
                $contact->set('firstName', $booking->firstName);
            }

            if ($booking->lastName) {
                $contact->set('lastName', $booking->lastName);
            }

            $contact->set('emailAddress', $booking->email);

            if ($booking->phone) {
                $contact->set('phoneNumber', $booking->phone);
            }

            // 'source' is a stock EspoCRM enum field on Contact in some setups; harmless if missing.
            $contact->set('source', 'Cal.com');

            if ($booking->fbc) {
                $contact->set('metaFbc', $booking->fbc);
            }

            if ($booking->fbp) {
                $contact->set('metaFbp', $booking->fbp);
            }

            $contact->set('metaCapturedAt', date('Y-m-d H:i:s'));

            // Tenancy propagation:
            //   - Set teamsIds from the integration so the Global Contact
            //     SyncTenantFromTeam hook can validate at save time.
            //   - Set tenantId directly as the source of truth (the hook
            //     short-circuits if tenantId is already set, so this won't
            //     conflict).
            // Without these, Contact.tenant (required: true) trips the ORM
            // validator and the webhook returns 500 — cal.com retries forever.
            $teamIds = $this->resolveIntegrationTeamIds($integration);
            $tenantId = $integration->get('tenantId');

            if (!empty($teamIds)) {
                $contact->set('teamsIds', $teamIds);
            }

            if ($tenantId) {
                $contact->set('tenantId', $tenantId);
            }

            $this->entityManager->saveEntity($contact);

            $this->log->info(sprintf(
                'MetaCapi cal.com: created Contact %s for email=%s (tenant=%s).',
                (string) $contact->getId(),
                $booking->email,
                (string) ($tenantId ?? ''),
            ));

            return $contact;
        } catch (Throwable $e) {
            $this->log->error(
                'MetaCapi cal.com: failed to create Contact: ' . $e->getMessage(),
            );

            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function resolveIntegrationTeamIds(CalComIntegration $integration): array
    {
        try {
            $ids = $integration->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $ids = [];
        }

        if (!empty($ids)) {
            return array_values(array_unique($ids));
        }

        $teamsIds = $integration->get('teamsIds');

        if (is_array($teamsIds) && !empty($teamsIds)) {
            return array_values(array_unique($teamsIds));
        }

        return [];
    }
}
