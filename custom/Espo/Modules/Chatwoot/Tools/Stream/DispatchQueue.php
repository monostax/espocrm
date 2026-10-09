<?php
declare(strict_types=1);
namespace Espo\Modules\Chatwoot\Tools\Stream;

use Espo\Core\Job\QueueName;
use Espo\ORM\Entity;

final class DispatchQueue
{
    public const AI = 'stream-ai';
    public const UI = 'stream-ui';

    public static function enabled(): bool
    {
        return getenv('CRM_STREAM_DISPATCH_ENABLED') === '1';
    }

    public static function execution(): string
    {
        return self::enabled() ? self::AI : QueueName::Q0;
    }

    public static function notification(Entity $entity): string
    {
        $data = $entity->get('data');
        $interactive = $entity->getEntityType() === 'AiSession' || $entity->get('parentType') === 'AiSession' ||
            ($entity->getEntityType() === 'Note' &&
                (!empty($data->opportunityAiMentionTargets) || !empty($data->opportunityStreamAgent)));
        return self::enabled() && $interactive ? self::UI : QueueName::Q0;
    }
}
