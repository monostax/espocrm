<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAgent;

use Espo\ORM\Entity;

/**
 * Hook to handle AI agent name prefix.
 * Prepends "✦ " to the name when isAI is true, removes it when false.
 * Runs BEFORE the entity is saved to the database.
 */
class HandleAIPrefix
{
    private const AI_PREFIX = '✦ ';

    public static int $order = 5; // Run early, before validation

    /**
     * Handle AI prefix on name before save.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        $name = $entity->get('name');
        
        if (!$name) {
            return;
        }

        $isAI = $entity->get('isAI');
        $hasPrefix = $this->hasAIPrefix($name);

        if ($isAI && !$hasPrefix) {
            // Add prefix if isAI is true and prefix is not present
            $entity->set('name', self::AI_PREFIX . $name);
        } elseif (!$isAI && $hasPrefix) {
            // Remove prefix if isAI is false and prefix is present
            $entity->set('name', $this->removeAIPrefix($name));
        }
    }

    /**
     * Check if name has the AI prefix.
     */
    private function hasAIPrefix(string $name): bool
    {
        return str_starts_with($name, self::AI_PREFIX);
    }

    /**
     * Remove the AI prefix from name.
     */
    private function removeAIPrefix(string $name): string
    {
        if ($this->hasAIPrefix($name)) {
            return substr($name, strlen(self::AI_PREFIX));
        }
        
        return $name;
    }
}
