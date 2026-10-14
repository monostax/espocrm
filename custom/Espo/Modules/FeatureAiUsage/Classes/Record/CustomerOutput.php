<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiUsage\Classes\Record;

use Espo\Core\Record\Output\Filter;
use Espo\ORM\Entity;

/** Raw telemetry remains available to internal ORM consumers, never generic record responses. */
final class CustomerOutput implements Filter
{
    private const ATTRIBUTES = ['id', 'name', 'kind', 'runAt', 'createdAt', 'modifiedAt', 'durationMs', 'leadTimeMs',
        'triggerMessageId', 'triggerMessageAt', 'sourceConversationId', 'toolsUsed', 'usageMetricsVersion',
        'modelRequestCount', 'totalTokens', 'runOutcome', 'billingWaived', 'billingWaivedAt',
        'wasTransferred', 'hadAppointment', 'createdOpportunity', 'updatedOpportunity', 'deletedOpportunity', 'sentFollowupMessage',
        'tenantId', 'tenantName', 'agentId', 'agentName', 'conversationId', 'conversationName',
        'opportunityId', 'opportunityName', 'sourceNoteId', 'sourceNoteName', 'chatwootAccountId', 'chatwootAccountName',
        'chatwootContactId', 'chatwootContactName', 'teamsIds', 'teamsNames'];

    public function filter(Entity $entity): void
    {
        foreach (array_keys(get_object_vars($entity->getValueMap())) as $field) {
            if (!self::allows($field)) $entity->clear($field);
        }
    }

    public static function allows(string $attribute): bool
    {
        return in_array($attribute, [...self::ATTRIBUTES, ...\Espo\Modules\FeatureAiUsage\Services\Analytics::FIELDS], true);
    }
}
