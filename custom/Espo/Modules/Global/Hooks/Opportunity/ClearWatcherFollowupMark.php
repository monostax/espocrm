<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\Opportunity;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * `followupByWatcher` says the current follow-up was scheduled by the opportunity watcher, whose follow-ups are sent
 * without taking the conversation from the person who owns it (2026-11-22). Anyone else scheduling a follow-up makes
 * it an ordinary one again. Runs after PauseFollowupForPersonStep (17), which may clear the timer first.
 *
 * @implements BeforeSave<Entity>
 */
class ClearWatcherFollowupMark implements BeforeSave
{
    public static int $order = 18;

    /** Save option the watcher's own saves carry. */
    public const OPTION = 'opportunityWatcher';

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get(self::OPTION) || !$entity->get('followupByWatcher')) {
            return;
        }
        if ($entity->isAttributeChanged('followupTimer') && $entity->get('followupTimer') !== null) {
            $entity->set('followupByWatcher', false);
        }
    }
}
