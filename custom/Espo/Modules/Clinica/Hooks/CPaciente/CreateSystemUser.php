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

namespace Espo\Modules\Clinica\Hooks\CPaciente;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\PasswordHash;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;

/**
 * Hook to automatically create a system user when a CPaciente is created.
 * Links the created user to the CPaciente via systemUser field.
 */
class CreateSystemUser implements AfterSave
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
        private PasswordHash $passwordHash,
        private Config $config,
        private Metadata $metadata
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        // Only run for new CPaciente records (not updates)
        if (!$entity->isNew()) {
            return;
        }

        // Skip if systemUser already exists
        if ($entity->get('systemUserId')) {
            return;
        }

        // Skip if no email address
        $emailAddress = $entity->get('emailAddress');
        if (!$emailAddress) {
            return;
        }

        try {
            // Check if a user with this email already exists
            $existingUser = $this->entityManager
                ->getRDBRepository(User::ENTITY_TYPE)
                ->where(['userName' => $emailAddress])
                ->findOne();

            if ($existingUser) {
                // Link existing user to this CPaciente
                $this->linkUser($entity, $existingUser);
                return;
            }

            // Create new user
            $user = $this->createUser($entity);
            
            if ($user) {
                // Link the new user to this CPaciente
                $this->linkUser($entity, $user);
            }
        } catch (\Exception $e) {
            // Don't fail CPaciente creation if user creation fails
        }
    }

    private function createUser(Entity $paciente): ?User
    {
        $firstName = $paciente->get('firstName');
        $lastName = $paciente->get('lastName');
        $emailAddress = $paciente->get('emailAddress');

        /** @var User $user */
        $user = $this->entityManager->getNewEntity(User::ENTITY_TYPE);
        
        // Set user basic info
        $user->set([
            'userName' => $emailAddress,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'emailAddress' => $emailAddress,
            'type' => 'regular', // Portal user by default
            'isActive' => true,
        ]);

        // Generate a random password
        $password = $this->generateRandomPassword();
        $passwordHash = $this->passwordHash->hash($password);
        $user->set('password', $passwordHash);

        // Save the user
        $this->entityManager->saveEntity($user);

        // Assign default team (Pacientes)
        $this->assignDefaultTeam($user);

        return $user;
    }

    private function linkUser(Entity $paciente, User $user): void
    {
        // Link the user to the CPaciente
        $this->entityManager
            ->getRDBRepository($paciente->getEntityType())
            ->getRelation($paciente, 'systemUser')
            ->relate($user);
    }

    private function generateRandomPassword(int $length = 16): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()';
        $charactersLength = strlen($characters);
        $randomPassword = '';
        
        for ($i = 0; $i < $length; $i++) {
            $randomPassword .= $characters[random_int(0, $charactersLength - 1)];
        }
        
        return $randomPassword;
    }

    private function assignDefaultTeam(User $user): void
    {
        try {
            // Check if UUID mode is enabled
            $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
                      $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

            // Get the Pacientes team ID
            $teamId = $this->prepareId('cm-pacientes', $toHash);

            // Check if team exists
            $team = $this->entityManager->getEntityById('Team', $teamId);
            
            if ($team) {
                // Add user to team
                $this->entityManager
                    ->getRDBRepository('User')
                    ->getRelation($user, 'teams')
                    ->relate($team);
            }
        } catch (\Exception $e) {
            // Silently fail if team assignment fails
        }
    }

    /**
     * Prepare ID for entity.
     * If UUID mode is enabled, returns MD5 hash of the ID.
     * Otherwise, returns the ID as-is.
     */
    private function prepareId(string $id, bool $toHash): string
    {
        if ($toHash) {
            return md5($id);
        }

        return $id;
    }
}

