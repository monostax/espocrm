<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Tools\Stream;

use Espo\Core\Utils\Acl\UserAclManagerProvider;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\TenantVisibility;
use Espo\Tools\Stream\NoteAccessControl;

/**
 * User.teams and User.defaultTeam are audited, so each workspace a staff
 * member joins is written into an Update note with every team name before and
 * after. Strip those changes for viewers who do not cover the user (see
 * TenantVisibility), wherever notes are output: streams, notifications,
 * email notifications and the Note record API.
 */
class TenantNoteAccessControl extends NoteAccessControl
{
    private const TEAM_FIELDS = [
        'teams' => ['teamsIds', 'teamsNames', 'teamsColumns'],
        'defaultTeam' => ['defaultTeamId', 'defaultTeamName'],
    ];

    public function __construct(
        UserAclManagerProvider $userAclManagerProvider,
        Metadata $metadata,
        private TenantVisibility $tenantVisibility,
    ) {
        parent::__construct($userAclManagerProvider, $metadata);
    }

    public function apply(Note $note, User $user): void
    {
        parent::apply($note, $user);

        if ($note->getType() !== Note::TYPE_UPDATE || $note->getParentType() !== User::ENTITY_TYPE) {
            return;
        }

        $data = $note->getData();
        $fields = $data->fields ?? [];

        if (!is_array($fields) || array_intersect(array_keys(self::TEAM_FIELDS), $fields) === []) {
            return;
        }

        $was = $data->attributes->was ?? null;
        $became = $data->attributes->became ?? null;
        $teamIds = [];

        foreach ([$was, $became] as $side) {
            if (!is_object($side)) {
                continue;
            }

            foreach ((array) ($side->teamsIds ?? []) as $id) {
                $teamIds[] = (string) $id;
            }

            if (is_string($side->defaultTeamId ?? null)) {
                $teamIds[] = $side->defaultTeamId;
            }
        }

        $hidden = $this->tenantVisibility->teamsHiddenFrom(
            $user,
            (string) $note->getParentId(),
            array_values(array_unique($teamIds)),
        );

        if ($hidden === []) {
            return;
        }

        $data->fields = array_values(array_diff($fields, array_keys(self::TEAM_FIELDS)));

        foreach (self::TEAM_FIELDS as $attributes) {
            foreach ($attributes as $attribute) {
                if (is_object($was)) {
                    unset($was->$attribute);
                }

                if (is_object($became)) {
                    unset($became->$attribute);
                }
            }
        }

        $note->setData($data);
    }
}
