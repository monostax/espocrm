<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Hooks\MetaLeadForm;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaFacebookPage;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadForm;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Cascades teams from the parent MetaFacebookPage to this lead form.
 *
 * Always runs (not just on insert) so re-saves with a different parent
 * stay consistent. Mirrors the Chatwoot ChatwootAccountWebhook
 * CascadeTeamsFromAccount pattern.
 *
 * @implements BeforeSave<MetaLeadForm>
 */
class CascadeTeamsFromPage implements BeforeSave
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof MetaLeadForm) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        $pageId = $entity->get('pageId');

        if (!$pageId) {
            return;
        }

        $page = $this->entityManager->getEntityById(MetaFacebookPage::ENTITY_TYPE, $pageId);

        if (!$page) {
            return;
        }

        try {
            $teamsIds = $page->getLinkMultipleIdList('teams');
        } catch (\Throwable) {
            return;
        }

        if (!empty($teamsIds)) {
            $entity->set('teamsIds', $teamsIds);
        }
    }
}
