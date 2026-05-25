<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaFacebookPage;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadForm;
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
        private Crypt $crypt,
        private Log $log,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   formsDiscovered: int,
     *   formsCreated: int,
     *   formsUpdated: int,
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

            $created = $this->upsertForm($formId, $name, $page);

            if ($created) {
                $result['formsCreated']++;
            } else {
                $result['formsUpdated']++;
            }
        }

        $result['ok'] = true;

        return $result;
    }

    private function upsertForm(string $formId, string $name, MetaFacebookPage $page): bool
    {
        $existing = $this->entityManager
            ->getRDBRepository(MetaLeadForm::ENTITY_TYPE)
            ->where(['formId' => $formId, 'deleted' => false])
            ->findOne();

        if ($existing instanceof MetaLeadForm) {
            // Only refresh display name + page link. Never touch funnel/stage/mapping/isActive.
            if ($name !== '' && $existing->get('name') !== $name) {
                $existing->set('name', $name);
            }
            $existing->set('pageId', $page->getId());

            $this->entityManager->saveEntity($existing, ['skipHooks' => true, 'silent' => true]);

            return false;
        }

        $form = $this->entityManager->getNewEntity(MetaLeadForm::ENTITY_TYPE);
        $form->set('name', $name !== '' ? $name : "Form {$formId}");
        $form->set('formId', $formId);
        $form->set('pageId', $page->getId());
        // Newly synced forms ship DISABLED — admin must assign a Funnel and enable.
        $form->set('isActive', false);
        $form->set('createOpportunity', true);

        $this->entityManager->saveEntity($form, ['skipHooks' => true, 'silent' => true]);

        return true;
    }
}
