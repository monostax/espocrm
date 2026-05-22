<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Contact;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Builds Meta Conversions API event payloads from CRM entities.
 *
 * Output shape matches:
 *   { "data": [{ "event_name": "...", "event_time": ..., "action_source": "system_generated",
 *               "user_data": {...}, "custom_data": {...}, "event_id": "..." }] }
 *
 * Reference: https://developers.facebook.com/docs/marketing-api/conversions-api/payload-helper
 */
class EventBuilder
{
    public function __construct(
        private EntityManager $entityManager,
        private UserDataHasher $hasher,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed>|null $extraCustomData Optional extra fields to merge into custom_data
     *                                                   (e.g. value, currency, content_name).
     * @param string|null $eventIdOverride Optional fixed event_id for dedupe with browser pixel.
     *                                     If null, a deterministic id is computed from
     *                                     (entityType, entityId, eventName, minuteBucket).
     *
     * @return array<string, mixed>|null  Null if entity cannot produce a valid event (no identity).
     */
    public function build(
        Entity $subject,
        string $eventName,
        string $leadEventSource,
        ?int $eventTime = null,
        ?array $extraCustomData = null,
        ?string $eventIdOverride = null,
    ): ?array {
        $eventTime = $eventTime ?? time();

        $contact = $this->resolveContact($subject);

        if (!$contact) {
            $this->log->info(sprintf(
                'MetaCapi EventBuilder: %s %s has no resolvable Contact; skipping event %s.',
                $subject->getEntityType(),
                (string) $subject->getId(),
                $eventName,
            ));

            return null;
        }

        $userData = $this->buildUserData($contact);

        if (!$this->hasAnyIdentity($userData)) {
            $this->log->info(sprintf(
                'MetaCapi EventBuilder: Contact %s has no usable identity fields; skipping event %s.',
                (string) $contact->getId(),
                $eventName,
            ));

            return null;
        }

        $customData = (object) [
            'event_source'      => 'crm',
            'lead_event_source' => $leadEventSource,
        ];

        if ($extraCustomData) {
            foreach ($extraCustomData as $k => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $customData->{$k} = $v;
            }
        }

        // Stable event_id for deduplication. Caller may pass a fixed id
        // (e.g. cal.com bookingUid) to dedupe with browser pixel events.
        $eventId = $eventIdOverride !== null && $eventIdOverride !== ''
            ? $eventIdOverride
            : $this->buildEventId($subject, $eventName, $eventTime);

        return [
            'event_name'    => $eventName,
            'event_time'    => $eventTime,
            'action_source' => 'system_generated',
            'event_id'      => $eventId,
            'user_data'     => $userData,
            'custom_data'   => $customData,
        ];
    }

    private function resolveContact(Entity $subject): ?Contact
    {
        if ($subject instanceof Contact) {
            return $subject;
        }

        if ($subject instanceof Opportunity) {
            $contactId = $subject->get('contactId');

            if (!$contactId) {
                return null;
            }

            $contact = $this->entityManager->getEntityById(Contact::ENTITY_TYPE, $contactId);

            return $contact instanceof Contact ? $contact : null;
        }

        return null;
    }

    /**
     * @return stdClass
     */
    private function buildUserData(Contact $contact): stdClass
    {
        $data = new stdClass();

        // Hashed identity (arrays per Meta spec).
        $email = $this->hasher->email((string) ($contact->get('emailAddress') ?? ''));
        if ($email !== null) {
            $data->em = [$email];
        }

        $phone = $this->hasher->phone((string) ($contact->get('phoneNumber') ?? ''));
        if ($phone !== null) {
            $data->ph = [$phone];
        }

        $firstName = $this->hasher->name((string) ($contact->get('firstName') ?? ''));
        if ($firstName !== null) {
            $data->fn = [$firstName];
        }

        $lastName = $this->hasher->name((string) ($contact->get('lastName') ?? ''));
        if ($lastName !== null) {
            $data->ln = [$lastName];
        }

        $address = $contact->get('addressCity') ?? null;
        if ($address) {
            $city = $this->hasher->city((string) $address);
            if ($city !== null) {
                $data->ct = [$city];
            }
        }

        $stateVal = $contact->get('addressState') ?? null;
        if ($stateVal) {
            $state = $this->hasher->state((string) $stateVal);
            if ($state !== null) {
                $data->st = [$state];
            }
        }

        $zipVal = $contact->get('addressPostalCode') ?? null;
        if ($zipVal) {
            $zip = $this->hasher->zip((string) $zipVal);
            if ($zip !== null) {
                $data->zp = [$zip];
            }
        }

        $countryVal = $contact->get('addressCountry') ?? null;
        if ($countryVal) {
            $country = $this->hasher->country((string) $countryVal);
            if ($country !== null) {
                $data->country = [$country];
            }
        }

        // NOT hashed: lead_id, fbc, fbp, external_id (per Meta spec).
        $leadId = $contact->get('metaLeadId');
        if ($leadId) {
            // Meta accepts lead_id as int. Cast safely.
            $data->lead_id = ctype_digit((string) $leadId)
                ? (int) $leadId
                : (string) $leadId;
        }

        $fbc = $contact->get('metaFbc');
        if ($fbc) {
            $data->fbc = (string) $fbc;
        }

        $fbp = $contact->get('metaFbp');
        if ($fbp) {
            $data->fbp = (string) $fbp;
        }

        // External ID — use Contact's own ID for cross-event linkage (recommended by Meta).
        $data->external_id = [hash('sha256', (string) $contact->getId())];

        return $data;
    }

    private function hasAnyIdentity(stdClass $userData): bool
    {
        // Need at least ONE of: em, ph, lead_id, fbc, external_id.
        // external_id is always set above, but it alone is weak for matching;
        // we still allow it because Meta will dedupe by other signals over time.
        foreach (['em', 'ph', 'lead_id', 'fbc', 'fbp', 'external_id'] as $key) {
            if (isset($userData->{$key})) {
                return true;
            }
        }

        return false;
    }

    private function buildEventId(Entity $subject, string $eventName, int $eventTime): string
    {
        // Bucket to the minute to allow benign retries to dedupe.
        $bucket = (int) floor($eventTime / 60);

        return hash('sha256', sprintf(
            '%s:%s:%s:%d',
            $subject->getEntityType(),
            (string) $subject->getId(),
            $eventName,
            $bucket,
        ));
    }
}
