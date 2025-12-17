<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Entities;

use Espo\Core\ORM\Entity;

class Funnel extends Entity
{
    public const ENTITY_TYPE = 'Funnel';

    public function getName(): ?string
    {
        return $this->get('name');
    }

    public function getTeamId(): ?string
    {
        return $this->get('teamId');
    }

    public function isActive(): bool
    {
        return (bool) $this->get('isActive');
    }

    public function isDefault(): bool
    {
        return (bool) $this->get('isDefault');
    }

    public function getDescription(): ?string
    {
        return $this->get('description');
    }

    public function setName(string $name): self
    {
        $this->set('name', $name);
        return $this;
    }

    public function setTeamId(string $teamId): self
    {
        $this->set('teamId', $teamId);
        return $this;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->set('isActive', $isActive);
        return $this;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->set('isDefault', $isDefault);
        return $this;
    }
}



