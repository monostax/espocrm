<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationCalCom\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Contact;
use Espo\ORM\EntityManager;

/**
 * Finds or creates a Contact based on a cal.com attendee.
 *
 * Match strategy:
 *   1. By emailAddress (canonical key — Meta also uses email as primary user_data field).
 *   2. If multiple matches exist, pick the most recently modified.
 *   3. If none and {@see $createIfMissing} is true, create a new Contact.
 *
 * Newly created Contacts get:
 *   - firstName, lastName, emailAddress, phoneNumber (from booking)
 *   - source = "Cal.com"  (free-text; harmless if the field doesn't exist on the tenant)
 *   - metaFbc, metaFbp (if present in booking)  -> drives match quality
 *   - metaCapturedAt = now
 *
 * Existing Contacts are augmented (never overwritten) with missing metaFbc/metaFbp/phone.
 */
class CalComContactMatcher
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function matchOrCreate(ParsedCalComBooking $booking, bool $createIfMissing): ?Contact
    {
        $contact = $this->findByEmail($booking->email);

        if ($contact) {
            $this->augmentExisting($contact, $booking);

            return $contact;
        }

        if (!$createIfMissing) {
            $this->log->info(sprintf(
                'MetaCapi cal.com: no Contact found for email=%s and createIfMissing=false; skipping.',
                $booking->email,
            ));

            return null;
        }

        return $this->createNew($booking);
    }

    private function findByEmail(string $email): ?Contact
    {
        $contact = $this->entityManager
            ->getRDBRepository(Contact::ENTITY_TYPE)
            ->where([
                'emailAddress' => $email,
                'deleted'      => false,
            ])
            ->order('modifiedAt', 'DESC')
            ->findOne();

        return $contact instanceof Contact ? $contact : null;
    }

    private function augmentExisting(Contact $contact, ParsedCalComBooking $booking): void
    {
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
            } catch (\Throwable $e) {
                $this->log->warning(
                    'MetaCapi cal.com: failed to augment existing Contact: ' . $e->getMessage(),
                );
            }
        }
    }

    private function createNew(ParsedCalComBooking $booking): ?Contact
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

            $this->entityManager->saveEntity($contact);

            $this->log->info(sprintf(
                'MetaCapi cal.com: created Contact %s for email=%s.',
                $contact->getId(),
                $booking->email,
            ));

            return $contact;
        } catch (\Throwable $e) {
            $this->log->error(
                'MetaCapi cal.com: failed to create Contact: ' . $e->getMessage(),
            );

            return null;
        }
    }
}
