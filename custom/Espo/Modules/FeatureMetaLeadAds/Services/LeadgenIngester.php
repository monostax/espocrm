<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Services;

use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Contact;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaFacebookPage;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadForm;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadFormQuestion;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadgenAnswer;
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
 *   9. Materialise one MetaLeadgenAnswer row per field_data entry, linking
 *      to the matching MetaLeadFormQuestion when available (synced by
 *      FormSyncService). Choice answers resolve their human label via the
 *      question's options dictionary so the structured table reads cleanly.
 *  10. Cosmetic summary: write a markdown Q&A block to Opportunity.description
 *      (or Contact.description as fallback when no Opportunity was created),
 *      ONLY if the target description is currently empty. Existing admin- or
 *      user-edited descriptions are never clobbered.
 *  11. Update MetaLeadgenEvent (status=Processed, contact, opportunity,
 *      processedAt, rawPayload=full Graph API response) and bump
 *      MetaLeadForm counters.
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

        // 2) Map field_data to Contact attributes (and track which keys produced one).
        $mapped = $this->fieldMapper->mapDetailed($fieldData, $leadForm);
        $contactAttrs = $mapped['attrs'];
        $mappedKeys   = $mapped['mappedKeys'];

        // 3) Dedup / upsert Contact.
        //
        // Tenant-scoped lookup: in shared-DB multi-tenant deployments, two
        // different tenants can legitimately own Contacts with the same
        // email/phone. Globally dedup would cause cross-tenant data leakage
        // (Tenant B's lead silently merged into Tenant A's contact, with
        // Tenant B's metaLeadId/metaAdId/metaFormId stamped on Tenant A's
        // row). We scope the dedup by the form's tenantId so each tenant
        // dedups within its own namespace.
        $tenantId = $leadForm->get('tenantId');

        if (!$tenantId) {
            // Form has no tenant — means the page wasn't assigned to a team,
            // or the team doesn't resolve to a tenant. We refuse to create
            // tenancy-less Contact rows because the Contact entityDefs marks
            // tenant as required and SyncTenantFromTeam would fail anyway.
            $this->markEventSkipped(
                $event,
                'MetaLeadForm has no tenant assigned (assign teams on parent MetaFacebookPage first).'
            );

            return;
        }

        $contact = $this->findExistingContact(
            (string) $event->get('leadgenId'),
            $contactAttrs,
            (string) $tenantId,
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

        // 5) Persist structured answers (one row per field_data entry).
        //    Done AFTER both Contact and Opportunity exist so the denormalised
        //    contactId/opportunityId can be set in a single insert pass.
        $answers = $this->persistAnswers(
            $event,
            $leadForm,
            $contact,
            $opportunity,
            $fieldData,
            $mappedKeys,
        );

        // 6) Cosmetic summary: drop a markdown block into Opportunity.description
        //    (or Contact.description as fallback when no Opportunity was created).
        //    Never overwrites existing non-empty text — only fills blanks.
        $this->writeAnswerSummary($leadForm, $contact, $opportunity, $answers);

        // 7) Mark event processed.
        $event->set('status',      MetaLeadgenEvent::STATUS_PROCESSED);
        $event->set('contactId',   $contact->getId());
        $event->set('opportunityId', $opportunity?->getId());
        $event->set('rawPayload',  $leadData);
        $event->set('processedAt', date('Y-m-d H:i:s'));
        $event->set('errorMessage', null);

        $this->entityManager->saveEntity($event, ['skipHooks' => true, 'silent' => true]);

        // 8) Bump form counters.
        $leadForm->set(
            'totalLeadsReceived',
            (int) ($leadForm->get('totalLeadsReceived') ?? 0) + 1,
        );
        $leadForm->set('lastLeadReceivedAt', date('Y-m-d H:i:s'));

        $this->entityManager->saveEntity($leadForm, ['skipHooks' => true, 'silent' => true]);

        $this->log->info(sprintf(
            'MetaLeadAds: processed leadgenId=%s -> Contact %s%s (%d answers).',
            $event->get('leadgenId'),
            $contact->getId(),
            $opportunity ? ", Opportunity {$opportunity->getId()}" : '',
            count($answers),
        ));
    }

    /**
     * Materialise structured MetaLeadgenAnswer rows from the raw field_data.
     *
     * Each row stores:
     *  - the original Meta key (`fieldKey`) and raw values array (`valueRaw`)
     *    so analytics queries can hit them without joining;
     *  - the denormalised question label (so display survives soft-deletion
     *    of the Question);
     *  - the human-resolved `value` (option key → option label when the
     *    parent Question is a choice question);
     *  - `wasMapped` to distinguish identity fields (email/phone/…) from
     *    qualification questions for downstream automation/reporting.
     *
     * @param array<int, array{name: string, values: array<int, string>}> $fieldData
     * @param array<string, true> $mappedKeys
     * @return list<MetaLeadgenAnswer>
     */
    private function persistAnswers(
        MetaLeadgenEvent $event,
        MetaLeadForm $form,
        Contact $contact,
        ?Opportunity $opportunity,
        array $fieldData,
        array $mappedKeys,
    ): array {
        $answers = [];

        // Preload the form's questions once so we don't N+1 the DB.
        /** @var array<string, MetaLeadFormQuestion> $questionsByKey */
        $questionsByKey = [];

        $questionRows = $this->entityManager
            ->getRDBRepository(MetaLeadFormQuestion::ENTITY_TYPE)
            ->where(['formId' => $form->getId(), 'deleted' => false])
            ->find();

        foreach ($questionRows as $q) {
            if ($q instanceof MetaLeadFormQuestion) {
                $questionsByKey[(string) $q->get('key')] = $q;
            }
        }

        $formTeamIds = $this->resolveFormTeamIds($form);
        $tenantId = $form->get('tenantId');

        foreach ($fieldData as $field) {
            $key = isset($field['name']) ? (string) $field['name'] : '';
            if ($key === '') {
                continue;
            }

            $values = isset($field['values']) && is_array($field['values']) ? $field['values'] : [];
            $stringValues = [];
            foreach ($values as $v) {
                if (is_scalar($v)) {
                    $stringValues[] = (string) $v;
                }
            }

            $question = $questionsByKey[$key] ?? null;
            $label = $question?->get('label') ?: $key;
            $displayValue = $this->resolveDisplayValue($stringValues, $question);

            try {
                $answer = $this->entityManager->getNewEntity(MetaLeadgenAnswer::ENTITY_TYPE);

                $answer->set('fieldKey', $key);
                $answer->set('label', $label);
                $answer->set('value', $displayValue);
                $answer->set('valueRaw', $stringValues);
                $answer->set('wasMapped', isset($mappedKeys[$key]));
                $answer->set('name', $this->buildAnswerName($label, $displayValue));

                $answer->set('eventId', $event->getId());
                if ($question) {
                    $answer->set('questionId', $question->getId());
                }
                $answer->set('formId', $form->getId());
                $answer->set('contactId', $contact->getId());
                if ($opportunity) {
                    $answer->set('opportunityId', $opportunity->getId());
                }

                // Tenancy propagation: hooks early-return on `silent`, so we
                // set explicitly. Mirrors LeadgenIngester::createNewContact.
                if (!empty($formTeamIds)) {
                    $answer->set('teamsIds', $formTeamIds);
                }
                if ($tenantId) {
                    $answer->set('tenantId', $tenantId);
                }

                $this->entityManager->saveEntity($answer, ['skipHooks' => true, 'silent' => true]);

                $answers[] = $answer;
            } catch (Throwable $e) {
                // One bad row should not abort the whole ingest; log and continue.
                $this->log->warning(sprintf(
                    'MetaLeadAds: failed to persist Answer for key=%s on event %s: %s',
                    $key,
                    $event->getId() ?? '(new)',
                    $e->getMessage(),
                ));
            }
        }

        return $answers;
    }

    /**
     * Resolve a display-friendly value from the raw values array.
     *
     * For choice questions, the raw values are option keys ("opt_1");
     * the parent Question's options dictionary maps them to human labels.
     * For free-text questions there's no dictionary, so we return the
     * values verbatim joined with ", ".
     *
     * @param list<string> $rawValues
     */
    private function resolveDisplayValue(array $rawValues, ?MetaLeadFormQuestion $question): string
    {
        if ($rawValues === []) {
            return '';
        }

        $optionMap = [];

        if ($question) {
            $options = $question->get('options');
            if (is_array($options)) {
                foreach ($options as $opt) {
                    if (!is_array($opt)) {
                        continue;
                    }
                    $k = isset($opt['key']) ? (string) $opt['key'] : '';
                    $v = isset($opt['value']) ? (string) $opt['value'] : '';
                    if ($k !== '') {
                        $optionMap[$k] = $v !== '' ? $v : $k;
                    }
                }
            }
        }

        $display = array_map(
            fn(string $raw) => $optionMap[$raw] ?? $raw,
            $rawValues,
        );

        return implode(', ', $display);
    }

    private function buildAnswerName(string $label, string $value): string
    {
        $name = trim($label) . ' → ' . trim($value);

        // Keep within varchar(250).
        if (mb_strlen($name) > 245) {
            $name = mb_substr($name, 0, 245) . '…';
        }

        return $name;
    }

    /**
     * Render a markdown Q&A summary and write it to Opportunity.description
     * (preferred) or Contact.description (fallback when there's no
     * Opportunity). Never overwrites a pre-existing non-empty description —
     * only fills blanks, so re-ingesting the same lead is a no-op.
     *
     * @param list<MetaLeadgenAnswer> $answers
     */
    private function writeAnswerSummary(
        MetaLeadForm $form,
        Contact $contact,
        ?Opportunity $opportunity,
        array $answers,
    ): void {
        if ($answers === []) {
            return;
        }

        $summary = $this->renderAnswerSummary($form, $answers);

        if ($summary === '') {
            return;
        }

        try {
            if ($opportunity instanceof Opportunity) {
                $existing = (string) ($opportunity->get('description') ?? '');
                if (trim($existing) === '') {
                    $opportunity->set('description', $summary);
                    $this->entityManager->saveEntity(
                        $opportunity,
                        ['skipHooks' => true, 'silent' => true],
                    );
                }

                return;
            }

            $existing = (string) ($contact->get('description') ?? '');
            if (trim($existing) === '') {
                $contact->set('description', $summary);
                $this->entityManager->saveEntity(
                    $contact,
                    ['skipHooks' => true, 'silent' => true],
                );
            }
        } catch (Throwable $e) {
            $this->log->warning('MetaLeadAds: failed to write answer summary: ' . $e->getMessage());
        }
    }

    /**
     * @param list<MetaLeadgenAnswer> $answers
     */
    private function renderAnswerSummary(MetaLeadForm $form, array $answers): string
    {
        $lines = [];
        $lines[] = '=== Meta Lead Ads — ' . ($form->get('name') ?: 'Lead') . ' ===';

        foreach ($answers as $a) {
            $label = (string) ($a->get('label') ?? $a->get('fieldKey'));
            $value = (string) ($a->get('value') ?? '');

            if ($label === '' && $value === '') {
                continue;
            }

            $lines[] = $label . ': ' . $value;
        }

        $lines[] = '=== / ===';

        return implode("\n", $lines);
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
     * All lookups are SCOPED to the current tenant — see doIngest()'s
     * justification. Cross-tenant matches are deliberately ignored so
     * each tenant dedups within its own namespace and we never silently
     * merge a lead from Tenant B into Tenant A's Contact.
     *
     * @param array<string, mixed> $attrs
     */
    private function findExistingContact(string $leadgenId, array $attrs, string $tenantId): ?Contact
    {
        $repo = $this->entityManager->getRDBRepository(Contact::ENTITY_TYPE);

        $byLeadId = $repo
            ->where(['metaLeadId' => $leadgenId, 'tenantId' => $tenantId, 'deleted' => false])
            ->order('modifiedAt', 'DESC')
            ->findOne();

        if ($byLeadId instanceof Contact) {
            return $byLeadId;
        }

        $email = $attrs['emailAddress'] ?? null;
        if (is_string($email) && $email !== '') {
            $byEmail = $repo
                ->where(['emailAddress' => $email, 'tenantId' => $tenantId, 'deleted' => false])
                ->order('modifiedAt', 'DESC')
                ->findOne();

            if ($byEmail instanceof Contact) {
                return $byEmail;
            }
        }

        $phone = $attrs['phoneNumber'] ?? null;
        if (is_string($phone) && $phone !== '') {
            $byPhone = $repo
                ->where(['phoneNumber' => $phone, 'tenantId' => $tenantId, 'deleted' => false])
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
        // Defense in depth: findExistingContact already filters by tenant
        // so this should never trip. But if a future code path bypasses
        // that filter, refuse to write attribution data onto a Contact
        // owned by a different tenant.
        $contactTenantId = (string) ($contact->get('tenantId') ?? '');
        $formTenantId = (string) ($leadForm->get('tenantId') ?? '');

        if ($contactTenantId !== '' && $formTenantId !== '' && $contactTenantId !== $formTenantId) {
            $this->log->error(sprintf(
                'MetaLeadAds: cross-tenant augment refused — Contact %s (tenant=%s) vs form %s (tenant=%s).',
                $contact->getId() ?? '(new)',
                $contactTenantId,
                $leadForm->getId() ?? '(new)',
                $formTenantId,
            ));

            return;
        }

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

            // Tenancy propagation:
            //   - Set teamsIds from the form so the Global Contact
            //     SyncTenantFromTeam (order=5) hook can derive tenantId
            //     via Tenant.baseUserTeam at save time.
            //   - Also set tenantId directly as a fallback (covers the case
            //     where the team→tenant mapping has drifted or the hook
            //     refuses to set it for any reason). SyncTenantFromTeam
            //     early-returns if tenantId is already set, so this won't
            //     conflict.
            $teamIds = $this->resolveFormTeamIds($leadForm);
            $tenantId = $leadForm->get('tenantId');

            if (!empty($teamIds)) {
                $contact->set('teamsIds', $teamIds);
            }

            if ($tenantId) {
                $contact->set('tenantId', $tenantId);
            }

            // Save WITH hooks so SyncTenantFromTeam and other Contact hooks
            // fire (validation, attribution, search index, etc.).
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

            // Tenancy propagation: same rationale as createNewContact.
            // Opportunity has no required tenant in stock metadata, but
            // RBAC visibility relies on `teams`. We propagate both for
            // belt-and-suspenders.
            $teamIds = $this->resolveFormTeamIds($leadForm);
            $tenantId = $leadForm->get('tenantId');

            if (!empty($teamIds)) {
                $opp->set('teamsIds', $teamIds);
            }

            if ($tenantId) {
                $opp->set('tenantId', $tenantId);
            }

            // Save WITHOUT skipHooks — we WANT the SendCapiOnStageChange hook to fire.
            $this->entityManager->saveEntity($opp);

            return $opp;
        } catch (Throwable $e) {
            $this->log->error('MetaLeadAds: failed to create Opportunity: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Read teams from a MetaLeadForm.
     *
     * @return list<string>
     */
    private function resolveFormTeamIds(MetaLeadForm $form): array
    {
        try {
            $ids = $form->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $ids = [];
        }

        if (!empty($ids)) {
            return array_values(array_unique($ids));
        }

        $teamsIds = $form->get('teamsIds');

        if (is_array($teamsIds) && !empty($teamsIds)) {
            return array_values(array_unique($teamsIds));
        }

        return [];
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
