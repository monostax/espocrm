<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaFacebookPage;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadForm;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadFormQuestion;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * One-shot synchronization of Lead Ads forms for a given Page.
 *
 * Triggered by admin pressing "Sync Forms" on a MetaFacebookPage detail
 * (controller `MetaFacebookPage::postActionSyncForms`).
 *
 * Steps:
 *   1. Load MetaFacebookPage; decrypt its pageAccessToken.
 *   2. GET /{pageId}/leadgen_forms → list forms.
 *   3. For each form, upsert MetaLeadForm by formId.
 *      - Newly created forms are saved with isActive=false because they
 *        need a Funnel assigned by the admin first. They appear in the
 *        list with status "needs config".
 *      - Existing forms only get name/page updated (don't overwrite
 *        funnel/stage/mapping).
 *
 * Returns a result array for the caller.
 */
class FormSyncService
{
    public function __construct(
        private EntityManager $entityManager,
        private MetaGraphApiClient $graphApiClient,
        private LeadFieldMapper $fieldMapper,
        private Crypt $crypt,
        private Log $log,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   formsDiscovered: int,
     *   formsCreated: int,
     *   formsUpdated: int,
     *   questionsCreated?: int,
     *   questionsUpdated?: int,
     *   questionsDeactivated?: int,
     *   error?: string,
     * }
     */
    public function syncForPage(string $pageInternalId): array
    {
        $result = [
            'ok'              => false,
            'formsDiscovered' => 0,
            'formsCreated'    => 0,
            'formsUpdated'    => 0,
            'questionsCreated'     => 0,
            'questionsUpdated'     => 0,
            'questionsDeactivated' => 0,
        ];

        $page = $this->entityManager
            ->getEntityById(MetaFacebookPage::ENTITY_TYPE, $pageInternalId);

        if (!$page instanceof MetaFacebookPage) {
            $result['error'] = 'MetaFacebookPage not found.';

            return $result;
        }

        $encrypted = (string) ($page->get('pageAccessToken') ?? '');
        if ($encrypted === '') {
            // Pages get their pageAccessToken populated by PageSyncService::syncForOAuthAccount,
            // which is reachable from two places in the UI:
            //   1. OAuthAccount detail   → button "Sync Meta Pages"
            //   2. MetaFacebookPage list → button "Sync Pages" (top of the list view)
            //   3. MetaFacebookPage detail → button "Sync Pages" (when oAuthAccount is linked)
            // Tell the user that explicitly so they don't go hunting.
            $result['error'] = 'Page has no access token yet. '
                . 'Click "Sync Pages" at the top of the Facebook Pages list (or on the linked OAuth Account) '
                . 'to pull pages and per-page tokens from Meta first.';

            return $result;
        }

        try {
            $token = $this->crypt->decrypt($encrypted);
        } catch (Throwable $e) {
            $result['error'] = 'Page access token unreadable.';

            return $result;
        }

        try {
            $forms = $this->graphApiClient->listForms(
                (string) $page->get('pageId'),
                $token,
            );
        } catch (Error $e) {
            $result['error'] = $e->getMessage();

            return $result;
        }

        $result['formsDiscovered'] = count($forms);

        foreach ($forms as $formData) {
            $formId = (string) ($formData['id'] ?? '');
            $name   = (string) ($formData['name'] ?? '');

            if ($formId === '') {
                continue;
            }

            [$formEntity, $created] = $this->upsertForm($formId, $name, $page);

            if ($created) {
                $result['formsCreated']++;
            } else {
                $result['formsUpdated']++;
            }

            // Pull the form's question schema and reconcile MetaLeadFormQuestion
            // rows so structured Answers have something to point at.
            if ($formEntity instanceof MetaLeadForm) {
                try {
                    $schema = $this->graphApiClient->fetchFormQuestions($formId, $token);

                    $qStats = $this->reconcileQuestions($formEntity, $schema['questions'] ?? []);

                    $result['questionsCreated']     += $qStats['created'];
                    $result['questionsUpdated']     += $qStats['updated'];
                    $result['questionsDeactivated'] += $qStats['deactivated'];
                } catch (Error $e) {
                    $this->log->warning(
                        "MetaLeadAds: failed to fetch question schema for form {$formId}: " . $e->getMessage()
                    );
                    // Non-fatal: ingestion will still work for standard fields
                    // via LeadFieldMapper defaults; only the structured Answer
                    // rows will lack a Question link.
                }
            }
        }

        $result['ok'] = true;

        return $result;
    }

    /**
     * @return array{0: ?MetaLeadForm, 1: bool} The persisted form entity and whether it was created.
     */
    private function upsertForm(string $formId, string $name, MetaFacebookPage $page): array
    {
        $existing = $this->entityManager
            ->getRDBRepository(MetaLeadForm::ENTITY_TYPE)
            ->where(['formId' => $formId, 'deleted' => false])
            ->findOne();

        // Propagate teams + tenant from the parent Page. Cascade hooks
        // (CascadeTeamsFromPage / CascadeTenantFromPage, both order=1)
        // would normally do this on save — but they early-return on
        // `silent`, and we save with `silent => true` to suppress Stream
        // notifications for system-internal sync rows. So we do the work
        // here explicitly to guarantee correct tenancy.
        $pageTeamIds = $this->resolvePageTeamIds($page);
        $pageTenantId = $page->get('tenantId');

        if ($existing instanceof MetaLeadForm) {
            // Only refresh display name + page link. Never touch funnel/stage/mapping/isActive.
            if ($name !== '' && $existing->get('name') !== $name) {
                $existing->set('name', $name);
            }
            $existing->set('pageId', $page->getId());

            // Backfill teams/tenant only if missing on the existing row
            // (don't overwrite admin choices).
            $this->backfillTeamsAndTenant($existing, $pageTeamIds, $pageTenantId);

            $this->entityManager->saveEntity($existing, ['skipHooks' => true, 'silent' => true]);

            return [$existing, false];
        }

        $form = $this->entityManager->getNewEntity(MetaLeadForm::ENTITY_TYPE);
        $form->set('name', $name !== '' ? $name : "Form {$formId}");
        $form->set('formId', $formId);
        $form->set('pageId', $page->getId());
        // Newly synced forms ship DISABLED — admin must assign a Funnel and enable.
        $form->set('isActive', false);
        $form->set('createOpportunity', true);

        if (!empty($pageTeamIds)) {
            $form->set('teamsIds', $pageTeamIds);
        }

        if ($pageTenantId) {
            $form->set('tenantId', $pageTenantId);
        }

        $this->entityManager->saveEntity($form, ['skipHooks' => true, 'silent' => true]);

        /** @var MetaLeadForm $form */
        return [$form, true];
    }

    /**
     * Materialise/refresh MetaLeadFormQuestion rows for one form from Meta's
     * questions schema.
     *
     * - Upserts by (formId, key). Existing rows have label/type/options/order
     *   refreshed and lastSeenAt bumped.
     * - Questions that vanished from Meta's schema are SOFT-deleted via
     *   isActive=false, so historical MetaLeadgenAnswer rows still resolve
     *   label/options through the link.
     *
     * Re-activates a previously deactivated question if Meta starts returning
     * it again (rare, but cheap to handle).
     *
     * @param array<int, array<string, mixed>> $questions Raw `questions` payload from Graph.
     * @return array{created: int, updated: int, deactivated: int}
     */
    private function reconcileQuestions(MetaLeadForm $form, array $questions): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'deactivated' => 0];

        $now = date('Y-m-d H:i:s');
        $contactAttrMap = $this->fieldMapper->getEffectiveMap($form);

        $seenKeys = [];

        foreach ($questions as $index => $q) {
            $key = isset($q['key']) ? (string) $q['key'] : '';

            if ($key === '') {
                continue;
            }

            $seenKeys[] = $key;

            $existing = $this->entityManager
                ->getRDBRepository(MetaLeadFormQuestion::ENTITY_TYPE)
                ->where(['formId' => $form->getId(), 'key' => $key, 'deleted' => false])
                ->findOne();

            $label     = isset($q['label']) ? (string) $q['label'] : $key;
            $type      = isset($q['type']) ? (string) $q['type'] : '';
            $inputType = isset($q['input_type']) ? (string) $q['input_type'] : '';
            $options   = $this->normaliseOptions($q['options'] ?? null);

            // Resolve contactAttribute via the effective fieldMapping (defaults +
            // per-form override). LeadFieldMapper uses '__fullName__' as a
            // marker for full_name — surface it as `firstName/lastName` for
            // display correctness without leaking the internal sentinel.
            $rawTarget = $contactAttrMap[$key] ?? null;
            $contactAttribute = $rawTarget === '__fullName__'
                ? 'firstName/lastName'
                : $rawTarget;
            $isStandard = $rawTarget !== null;

            if ($existing instanceof MetaLeadFormQuestion) {
                $existing->set('name', $label);
                $existing->set('label', $label);
                $existing->set('type', $type);
                $existing->set('inputType', $inputType);
                $existing->set('options', $options);
                $existing->set('order', $index);
                $existing->set('isStandard', $isStandard);
                $existing->set('contactAttribute', $contactAttribute);
                $existing->set('lastSeenAt', $now);

                // Re-activate if previously soft-deleted.
                if (!$existing->get('isActive')) {
                    $existing->set('isActive', true);
                }

                $this->entityManager->saveEntity($existing, ['skipHooks' => true, 'silent' => true]);
                $stats['updated']++;

                continue;
            }

            $question = $this->entityManager->getNewEntity(MetaLeadFormQuestion::ENTITY_TYPE);
            $question->set('name', $label);
            $question->set('key', $key);
            $question->set('label', $label);
            $question->set('type', $type);
            $question->set('inputType', $inputType);
            $question->set('options', $options);
            $question->set('order', $index);
            $question->set('isStandard', $isStandard);
            $question->set('contactAttribute', $contactAttribute);
            $question->set('isActive', true);
            $question->set('lastSeenAt', $now);
            $question->set('formId', $form->getId());

            // Propagate tenant/teams explicitly: hooks early-return on `silent`.
            $formTeamIds = $this->resolveFormTeamIds($form);
            $formTenantId = $form->get('tenantId');

            if (!empty($formTeamIds)) {
                $question->set('teamsIds', $formTeamIds);
            }

            if ($formTenantId) {
                $question->set('tenantId', $formTenantId);
            }

            $this->entityManager->saveEntity($question, ['skipHooks' => true, 'silent' => true]);
            $stats['created']++;
        }

        // Soft-delete (deactivate) questions no longer present in the schema.
        if (!empty($seenKeys)) {
            $stale = $this->entityManager
                ->getRDBRepository(MetaLeadFormQuestion::ENTITY_TYPE)
                ->where([
                    'formId'     => $form->getId(),
                    'isActive'   => true,
                    'key!='      => $seenKeys,
                    'deleted'    => false,
                ])
                ->find();

            foreach ($stale as $row) {
                $row->set('isActive', false);
                $this->entityManager->saveEntity($row, ['skipHooks' => true, 'silent' => true]);
                $stats['deactivated']++;
            }
        }

        return $stats;
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function normaliseOptions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $opt) {
            if (!is_array($opt)) {
                continue;
            }
            $k = isset($opt['key']) ? (string) $opt['key'] : '';
            $v = isset($opt['value']) ? (string) $opt['value'] : $k;

            if ($k === '') {
                continue;
            }

            $out[] = ['key' => $k, 'value' => $v];
        }

        return $out;
    }

    /**
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

    /**
     * @return list<string>
     */
    private function resolvePageTeamIds(MetaFacebookPage $page): array
    {
        try {
            $ids = $page->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $ids = [];
        }

        if (!empty($ids)) {
            return array_values(array_unique($ids));
        }

        $teamsIds = $page->get('teamsIds');

        if (is_array($teamsIds) && !empty($teamsIds)) {
            return array_values(array_unique($teamsIds));
        }

        return [];
    }

    /**
     * @param list<string> $teamIds
     */
    private function backfillTeamsAndTenant(
        MetaLeadForm $entity,
        array $teamIds,
        ?string $tenantId,
    ): void {
        if (empty($teamIds)) {
            return;
        }

        $currentTeamIds = [];
        try {
            $currentTeamIds = $entity->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $currentTeamIds = [];
        }

        if (empty($currentTeamIds)) {
            $entity->set('teamsIds', $teamIds);
        }

        if ($tenantId && !$entity->get('tenantId')) {
            $entity->set('tenantId', $tenantId);
        }
    }
}
