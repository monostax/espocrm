<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Services;

use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Contact;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaFacebookPage;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadForm;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadgenEvent;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Ingests a single leadgen submission end-to-end.
 *
 * Sequence (invoked from `IngestLeadgen` job):
 *
 *   1. Load the MetaLeadgenEvent (idempotent — already created by webhook controller).
 *   2. Skip-out if event is already Processed (Meta sometimes redelivers).
 *   3. Resolve MetaLeadForm (must be active) + MetaFacebookPage (must be active).
 *   4. Decrypt the Page Access Token.
 *   5. GET /{leadgenId} from Meta Graph API.
 *   6. Dedup Contact: metaLeadId → emailAddress → phoneNumber.
 *      6a. If existing Contact, augment missing fields (never overwrite).
 *      6b. Else create new Contact populated from the field_data via LeadFieldMapper.
 *   7. Persist meta* attribution fields (metaLeadId, metaAdId, metaCampaignId,
 *      metaFormId, metaCapturedAt) on the Contact.
 *   8. If form.createOpportunity → create Opportunity in form.funnel +
 *      (form.opportunityStage OR first active stage of funnel). This Opp save
 *      naturally triggers the existing `SendCapiOnStageChange` hook, so the
 *      Lead event is dispatched to Meta CAPI automatically. The hook's
 *      stage→eventName map should include the chosen stage with eventName
 *      `Lead` for the round-trip to close.
 *   9. Update MetaLeadgenEvent (status=Processed, contact, opportunity,
 *      processedAt, rawPayload) and bump MetaLeadForm counters.
 *
 * On any thrown exception inside the per-event scope, the event is marked
 * Failed with the error message — never throws back to the caller. This is
 * important because the caller is a Job (retries are wasteful for permanent
 * failures like a deleted lead).
 */
class LeadgenIngester
{
    public function __construct(
        private EntityManager $entityManager,
        private MetaGraphApiClient $graphApiClient,
        private LeadFieldMapper $fieldMapper,
        private Crypt $crypt,
        private Log $log,
    ) {}

    /**
     * Drive the full ingestion for one leadgen event id.
     */
    public function ingest(string $eventId): void
    {
        $event = $this->entityManager
            ->getEntityById(MetaLeadgenEvent::ENTITY_TYPE, $eventId);

        if (!$event instanceof MetaLeadgenEvent) {
            $this->log->warning("MetaLeadAds: MetaLeadgenEvent {$eventId} not found; ignoring.");

            return;
        }

        if ($event->get('status') === MetaLeadgenEvent::STATUS_PROCESSED) {
            // Idempotency: Meta may redeliver the same leadgen webhook.
            $this->log->info("MetaLeadAds: event {$eventId} already processed; ignoring.");

            return;
        }

        try {
            $this->doIngest($event);
        } catch (Throwable $e) {
            $this->log->error('MetaLeadAds: ingestion failed: ' . $e->getMessage(), [
                'eventId'   => $eventId,
                'leadgenId' => $event->get('leadgenId'),
                'trace'     => $e->getTraceAsString(),
            ]);

            $this->markEventFailed($event, $e->getMessage());
        }
    }

    private function doIngest(MetaLeadgenEvent $event): void
    {
        $leadForm = $this->resolveLeadForm($event);

        if (!$leadForm) {
            $this->markEventSkipped($event, sprintf(
                'No MetaLeadForm configured for formId=%s.',
                (string) $event->get('formId'),
            ));

            return;
        }

        if (!$leadForm->get('isActive')) {
            $this->markEventSkipped($event, 'MetaLeadForm is inactive.');

            return;
        }

        if (!$leadForm->get('funnelId')) {
            $this->markEventSkipped($event, 'MetaLeadForm has no Funnel configured; not ready for ingestion.');

            return;
        }

        $page = $this->resolvePage($leadForm);

        if (!$page) {
            $this->markEventSkipped($event, 'MetaFacebookPage linked to form not found.');

            return;
        }

        if (!$page->get('isActive')) {
            $this->markEventSkipped($event, 'MetaFacebookPage is inactive.');

            return;
        }

        $pageToken = $this->decryptPageToken($page);

        if ($pageToken === null) {
            throw new \RuntimeException('Page Access Token is missing or unreadable.');
        }

        // 1) Fetch the lead from Meta.
        $leadData = $this->graphApiClient->fetchLeadgen(
            (string) $event->get('leadgenId'),
            $pageToken,
        );

        $fieldData = is_array($leadData['field_data'] ?? null) ? $leadData['field_data'] : [];

        // 2) Map field_data to Contact attributes.
        $contactAttrs = $this->fieldMapper->map($fieldData, $leadForm);

        // 3) Dedup / upsert Contact.
        $contact = $this->findExistingContact(
            (string) $event->get('leadgenId'),
            $contactAttrs,
        );

        if ($contact) {
            $this->augmentExistingContact($contact, $contactAttrs, $event, $leadForm);
        } else {
            $contact = $this->createNewContact($contactAttrs, $event, $leadForm);

            if (!$contact) {
                throw new \RuntimeException('Failed to create Contact.');
            }
        }

        // 4) Optionally create Opportunity.
        $opportunity = null;

        if ($leadForm->get('createOpportunity')) {
            $opportunity = $this->createOpportunity($contact, $leadForm, $leadData);
        }

        // 5) Mark event processed.
        $event->set('status',      MetaLeadgenEvent::STATUS_PROCESSED);
        $event->set('contactId',   $contact->getId());
        $event->set('opportunityId', $opportunity?->getId());
        $event->set('rawPayload',  $leadData);
        $event->set('processedAt', date('Y-m-d H:i:s'));
        $event->set('errorMessage', null);

        $this->entityManager->saveEntity($event, ['skipHooks' => true, 'silent' => true]);

        // 6) Bump form counters.
        $leadForm->set(
            'totalLeadsReceived',
            (int) ($leadForm->get('totalLeadsReceived') ?? 0) + 1,
        );
        $leadForm->set('lastLeadReceivedAt', date('Y-m-d H:i:s'));

        $this->entityManager->saveEntity($leadForm, ['skipHooks' => true, 'silent' => true]);

        $this->log->info(sprintf(
            'MetaLeadAds: processed leadgenId=%s -> Contact %s%s.',
            $event->get('leadgenId'),
            $contact->getId(),
            $opportunity ? ", Opportunity {$opportunity->getId()}" : '',
        ));
    }

    private function resolveLeadForm(MetaLeadgenEvent $event): ?MetaLeadForm
    {
        $formId = (string) $event->get('formId');
        if ($formId === '') {
            return null;
        }

        $form = $this->entityManager
            ->getRDBRepository(MetaLeadForm::ENTITY_TYPE)
            ->where(['formId' => $formId, 'deleted' => false])
            ->findOne();

        return $form instanceof MetaLeadForm ? $form : null;
    }

    private function resolvePage(MetaLeadForm $form): ?MetaFacebookPage
    {
        $pageInternalId = $form->get('pageId'); // link id, NOT meta numeric

        if (!$pageInternalId) {
            return null;
        }

        $page = $this->entityManager
            ->getEntityById(MetaFacebookPage::ENTITY_TYPE, $pageInternalId);

        return $page instanceof MetaFacebookPage ? $page : null;
    }

    private function decryptPageToken(MetaFacebookPage $page): ?string
    {
        $encrypted = (string) ($page->get('pageAccessToken') ?? '');

        if ($encrypted === '') {
            return null;
        }

        try {
            return $this->crypt->decrypt($encrypted);
        } catch (Throwable $e) {
            $this->log->error('MetaLeadAds: failed to decrypt pageAccessToken: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Find an existing Contact by:
     *  1. metaLeadId (strongest idempotency on Meta side)
     *  2. emailAddress
     *  3. phoneNumber
     *
     * @param array<string, mixed> $attrs
     */
    private function findExistingContact(string $leadgenId, array $attrs): ?Contact
    {
        $repo = $this->entityManager->getRDBRepository(Contact::ENTITY_TYPE);

        $byLeadId = $repo
            ->where(['metaLeadId' => $leadgenId, 'deleted' => false])
            ->order('modifiedAt', 'DESC')
            ->findOne();

        if ($byLeadId instanceof Contact) {
            return $byLeadId;
        }

        $email = $attrs['emailAddress'] ?? null;
        if (is_string($email) && $email !== '') {
            $byEmail = $repo
                ->where(['emailAddress' => $email, 'deleted' => false])
                ->order('modifiedAt', 'DESC')
                ->findOne();

            if ($byEmail instanceof Contact) {
                return $byEmail;
            }
        }

        $phone = $attrs['phoneNumber'] ?? null;
        if (is_string($phone) && $phone !== '') {
            $byPhone = $repo
                ->where(['phoneNumber' => $phone, 'deleted' => false])
                ->order('modifiedAt', 'DESC')
                ->findOne();

            if ($byPhone instanceof Contact) {
                return $byPhone;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function augmentExistingContact(
        Contact $contact,
        array $attrs,
        MetaLeadgenEvent $event,
        MetaLeadForm $leadForm,
    ): void {
        $changed = false;

        // Never overwrite existing identity fields; only fill blanks.
        foreach (['firstName', 'lastName', 'emailAddress', 'phoneNumber', 'addressCity', 'accountName', 'title'] as $field) {
            if (!$contact->get($field) && isset($attrs[$field]) && $attrs[$field] !== '') {
                $contact->set($field, $attrs[$field]);
                $changed = true;
            }
        }

        // Always (re)stamp meta attribution — this is the latest source of truth for it.
        $contact->set('metaLeadId',     $event->get('leadgenId'));
        $contact->set('metaAdId',       $event->get('adId') ?: $contact->get('metaAdId'));
        $contact->set('metaFormId',     $event->get('formId'));
        $contact->set('metaCapturedAt', $contact->get('metaCapturedAt') ?: date('Y-m-d H:i:s'));

        if ($leadForm->get('assignedUserId') && !$contact->get('assignedUserId')) {
            $contact->set('assignedUserId', $leadForm->get('assignedUserId'));
            $changed = true;
        }

        try {
            $this->entityManager->saveEntity($contact, ['skipHooks' => true, 'silent' => true]);
            unset($changed); // suppress unused
        } catch (Throwable $e) {
            $this->log->warning('MetaLeadAds: failed to augment Contact: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function createNewContact(
        array $attrs,
        MetaLeadgenEvent $event,
        MetaLeadForm $leadForm,
    ): ?Contact {
        try {
            /** @var Contact $contact */
            $contact = $this->entityManager->getNewEntity(Contact::ENTITY_TYPE);

            foreach ($attrs as $name => $value) {
                if ($value !== null && $value !== '') {
                    $contact->set($name, $value);
                }
            }

            // Default `source` enum to "Meta Lead Ads" (Espo's stock Contact has a `source` field).
            $contact->set('source', 'Meta Lead Ads');

            // Meta attribution.
            $contact->set('metaLeadId',     $event->get('leadgenId'));
            $contact->set('metaAdId',       $event->get('adId'));
            $contact->set('metaFormId',     $event->get('formId'));
            $contact->set('metaCapturedAt', date('Y-m-d H:i:s'));

            if ($leadForm->get('assignedUserId')) {
                $contact->set('assignedUserId', $leadForm->get('assignedUserId'));
            }

            $this->entityManager->saveEntity($contact);

            return $contact;
        } catch (Throwable $e) {
            $this->log->error('MetaLeadAds: failed to create Contact: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @param array<string, mixed> $leadData Decoded GET /{leadgenId} response.
     */
    private function createOpportunity(
        Contact $contact,
        MetaLeadForm $leadForm,
        array $leadData,
    ): ?Opportunity {
        try {
            /** @var Opportunity $opp */
            $opp = $this->entityManager->getNewEntity(Opportunity::ENTITY_TYPE);

            $oppName = sprintf(
                '%s — %s',
                $contact->get('name') ?: trim(
                    ($contact->get('firstName') ?? '') . ' ' . ($contact->get('lastName') ?? '')
                ) ?: 'New Lead',
                $leadForm->get('name') ?? 'Lead',
            );

            $opp->set('name',      $oppName);
            $opp->set('contactId', $contact->getId());
            $opp->set('accountId', $contact->get('accountId'));
            $opp->set('funnelId',  $leadForm->get('funnelId'));

            $stageId = $leadForm->get('opportunityStageId') ?: $this->firstStageId($leadForm->get('funnelId'));

            if ($stageId) {
                $opp->set('opportunityStageId', $stageId);
            }

            if ($leadForm->get('assignedUserId')) {
                $opp->set('assignedUserId', $leadForm->get('assignedUserId'));
            }

            // Closing date: now + 30 days (heuristic; tenant can change manually).
            $opp->set('closeDate', date('Y-m-d', strtotime('+30 days')));

            // Save WITHOUT skipHooks — we WANT the SendCapiOnStageChange hook to fire.
            $this->entityManager->saveEntity($opp);

            return $opp;
        } catch (Throwable $e) {
            $this->log->error('MetaLeadAds: failed to create Opportunity: ' . $e->getMessage());

            return null;
        }
    }

    private function firstStageId(?string $funnelId): ?string
    {
        if (!$funnelId) {
            return null;
        }

        $stage = $this->entityManager
            ->getRDBRepository('OpportunityStage')
            ->where(['funnelId' => $funnelId, 'isActive' => true, 'deleted' => false])
            ->order('order', 'ASC')
            ->findOne();

        return $stage ? $stage->getId() : null;
    }

    private function markEventSkipped(MetaLeadgenEvent $event, string $reason): void
    {
        $event->set('status',       MetaLeadgenEvent::STATUS_SKIPPED);
        $event->set('errorMessage', $reason);
        $event->set('processedAt',  date('Y-m-d H:i:s'));

        try {
            $this->entityManager->saveEntity($event, ['skipHooks' => true, 'silent' => true]);
        } catch (Throwable $e) {
            $this->log->warning('MetaLeadAds: failed to persist skipped event: ' . $e->getMessage());
        }
    }

    private function markEventFailed(MetaLeadgenEvent $event, string $error): void
    {
        $event->set('status',       MetaLeadgenEvent::STATUS_FAILED);
        $event->set('errorMessage', $error);
        $event->set('processedAt',  date('Y-m-d H:i:s'));

        try {
            $this->entityManager->saveEntity($event, ['skipHooks' => true, 'silent' => true]);
        } catch (Throwable $e) {
            $this->log->warning('MetaLeadAds: failed to persist failed event: ' . $e->getMessage());
        }
    }
}
