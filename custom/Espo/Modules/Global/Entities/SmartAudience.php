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

class SmartAudience extends Entity
{
    public const ENTITY_TYPE = 'SmartAudience';

    public const EXIT_RULE_ON_REPLY = 'on_reply';
    public const EXIT_RULE_ON_CRITERIA_CHANGE = 'on_criteria_change';
    public const EXIT_RULE_MANUAL = 'manual';

    public const AUTONOMY_MODE_MANUAL = 'manual';
    public const AUTONOMY_MODE_SUGGEST = 'suggest';
    public const AUTONOMY_MODE_SEMI_AUTO = 'semi_auto';
    public const AUTONOMY_MODE_FULL_AUTO = 'full_auto';

    public function getName(): ?string
    {
        return $this->get('name');
    }

    public function getDescription(): ?string
    {
        return $this->get('description');
    }

    public function getTargetEntityType(): ?string
    {
        return $this->get('entityType');
    }

    public function getReportId(): ?string
    {
        return $this->get('reportId');
    }

    public function getClassificationPrompt(): ?string
    {
        return $this->get('classificationPrompt');
    }

    public function getExitRule(): ?string
    {
        return $this->get('exitRule');
    }

    public function isActive(): bool
    {
        return (bool) $this->get('isActive');
    }

    public function isAgentEnabled(): bool
    {
        return (bool) $this->get('agentEnabled');
    }

    public function getAgentSystemPrompt(): ?string
    {
        return $this->get('agentSystemPrompt');
    }

    public function getAgentObjective(): ?string
    {
        return $this->get('agentObjective');
    }

    public function getAgentTalkingPoints(): ?array
    {
        return $this->get('agentTalkingPoints');
    }

    public function getAgentAutonomyMode(): ?string
    {
        return $this->get('agentAutonomyMode');
    }

    public function getAgentMaxActions(): int
    {
        return (int) $this->get('agentMaxActions');
    }

    public function getAgentCooldownHours(): int
    {
        return (int) $this->get('agentCooldownHours');
    }

    public function isAgentBusinessHoursOnly(): bool
    {
        return (bool) $this->get('agentBusinessHoursOnly');
    }

    public function setName(string $name): self
    {
        $this->set('name', $name);
        return $this;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->set('isActive', $isActive);
        return $this;
    }

    public function setAgentEnabled(bool $agentEnabled): self
    {
        $this->set('agentEnabled', $agentEnabled);
        return $this;
    }
}
