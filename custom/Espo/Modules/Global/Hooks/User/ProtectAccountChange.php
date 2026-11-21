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

namespace Espo\Modules\Global\Hooks\User;

use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\TenantAdminScope;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Repositories\EmailAddress as EmailAddressRepository;

/**
 * Tenant roles let every member edit users in their teams. Two changes to
 * someone else's account need more than that:
 *
 * - Email addresses (primary or secondary): the email is the login
 *   (EmailIdentity), password recovery mails it, Google and Microsoft sign-in
 *   match it and it is pushed to Chatwoot. Whoever sets it owns the account.
 *   Instance admins only.
 * - Active flag of a person: deactivating locks them out of every workspace.
 *   Only someone who administers all of the person's workspaces.
 *
 * An ORM hook so bulk update, which skips record hooks, is covered too.
 * Runs after EmailIdentity has normalised the addresses.
 */
class ProtectAccountChange
{
    public static int $order = 0;

    /**
     * Save option for internal flows that already verified the actor
     * administers the workspace and change membership before the active flag
     * (seat removal unlinks the teams first). Not settable from the API.
     */
    public const WORKSPACE_ADMIN_VERIFIED = 'workspaceAdminVerified';

    public function __construct(
        private ApplicationState $applicationState,
        private EntityManager $entityManager,
        private TenantAdminScope $tenantAdminScope,
    ) {}

    /**
     * @param array<string, mixed> $options
     * @throws Forbidden
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        if (!$entity instanceof User || $entity->isNew() || !$this->applicationState->isLogged()) {
            return;
        }

        $actor = $this->applicationState->getUser();

        if ($actor->isAdmin() || $actor->getId() === $entity->getId()) {
            return;
        }

        if (
            ($entity->isAttributeChanged('emailAddress') || $entity->isAttributeChanged('emailAddressData')) &&
            $this->addresses($entity) !== $this->storedAddresses($entity)
        ) {
            throw new Forbidden("Only an administrator can change another user's email address.");
        }

        if (
            $entity->isAttributeChanged('isActive') &&
            ($entity->isRegular() || $entity->isAdmin()) &&
            empty($options[self::WORKSPACE_ADMIN_VERIFIED]) &&
            !$this->tenantAdminScope->administers($actor, $entity)
        ) {
            throw new Forbidden('Only an administrator of the workspace can deactivate or reactivate a user.');
        }
    }

    /**
     * @return list<string>
     */
    private function addresses(User $entity): array
    {
        $list = [];

        $data = $entity->get('emailAddressData');

        if (is_array($data) && $entity->isAttributeChanged('emailAddressData')) {
            foreach ($data as $row) {
                $address = is_object($row) ? ($row->emailAddress ?? null) : ($row['emailAddress'] ?? null);

                if (is_string($address) && $address !== '') {
                    $list[] = strtolower(trim($address));
                }
            }
        } else {
            $list = $this->storedAddresses($entity);
            $primary = $entity->get('emailAddress');

            if (is_string($primary) && $primary !== '') {
                $list[] = strtolower(trim($primary));
            }
        }

        $list = array_values(array_unique($list));
        sort($list);

        return $list;
    }

    /**
     * @return list<string>
     */
    private function storedAddresses(User $entity): array
    {
        /** @var EmailAddressRepository $repository */
        $repository = $this->entityManager->getRepository('EmailAddress');

        $list = [];

        foreach ($repository->getEmailAddressData($entity) as $row) {
            $address = $row->emailAddress ?? null;

            if (is_string($address) && $address !== '') {
                $list[] = strtolower(trim($address));
            }
        }

        $list = array_values(array_unique($list));
        sort($list);

        return $list;
    }
}
