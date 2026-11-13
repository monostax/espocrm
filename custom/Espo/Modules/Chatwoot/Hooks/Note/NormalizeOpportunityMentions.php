<?php

namespace Espo\Modules\Chatwoot\Hooks\Note;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\Modules\Chatwoot\Services\OpportunityPostMentions;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\FeatureCredits\Accounting\AgreementModels;

class NormalizeOpportunityMentions implements BeforeSave
{
    // Native Espo mentions are populated at order 9.
    public static int $order = 20;

    public function __construct(private OpportunityPostMentions $mentions, private User $user, private AgreementModels $models) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (in_array($entity->get('parentType'), ['Opportunity', ...ActivityDiscussion::PARENT_TYPES], true) && $entity->get('type') === Note::TYPE_POST &&
            ($entity->isNew() || $entity->isAttributeChanged('post'))) {
            assert($entity instanceof Note);
            $entity->set('opportunityMentionUserIds', $this->mentions->resolve($entity));
            if ($entity->isNew()) {
                // Note.data is server-owned. Keep the author-authorized targets for the async worker.
                $data = $entity->getData();
                // Capture the authenticated posting identity, never client-supplied createdBy.
                $data->opportunityAiInitiatorUserId = $this->user->getId();
                $targets = $this->mentions->resolveAiTargets($entity);
                // The model picked for the mentioned AI agents, priced by the tenant's agreement.
                // An unavailable model rejects the post; it is never dropped silently.
                $model = $entity->get('opportunityAiModel');
                if ($model !== null && $model !== '' && $targets) {
                    if (!is_string($model)) throw new BadRequest('Invalid AI model.');
                    foreach (array_unique(array_column($targets, 'crmTenantId')) as $tenantId) {
                        if (!$this->models->allows((string) $tenantId, $model)) throw new BadRequest('This model is not available in this workspace.');
                    }
                    foreach ($targets as $target) $target->model = $model;
                }
                $data->opportunityAiMentionTargets = $targets;
                $entity->setData($data);
            }
        }
    }
}
