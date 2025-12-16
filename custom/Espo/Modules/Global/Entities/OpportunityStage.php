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

class OpportunityStage extends Entity
{
    public const ENTITY_TYPE = 'OpportunityStage';

    public function getName(): ?string
    {
        return $this->get('name');
    }

    public function getFunnelId(): ?string
    {
        return $this->get('funnelId');
    }

    public function getOrder(): int
    {
        return $this->get('order') ?? 10;
    }

    public function getProbability(): int
    {
        return $this->get('probability') ?? 0;
    }

    public function getStyle(): ?string
    {
        return $this->get('style');
    }

    public function isActive(): bool
    {
        return (bool) $this->get('isActive');
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

    public function setFunnelId(string $funnelId): self
    {
        $this->set('funnelId', $funnelId);
        return $this;
    }

    public function setOrder(int $order): self
    {
        $this->set('order', $order);
        return $this;
    }

    public function setProbability(int $probability): self
    {
        $this->set('probability', $probability);
        return $this;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->set('isActive', $isActive);
        return $this;
    }
}
