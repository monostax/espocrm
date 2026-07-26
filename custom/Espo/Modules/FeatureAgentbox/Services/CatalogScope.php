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

namespace Espo\Modules\FeatureAgentbox\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Entities\User;
use stdClass;

/**
 * Catalog workspace binding for AgentSkill / AgentMode virtual records.
 *
 * Composite id (new):
 *   {tenantId}::{workspaceKind}::{entityRef}::{name}
 * entityRef is "-" for tenant-shared / crm-global; userId; membershipId;
 * or "{chatwootAccountCrmId}~{contactId}" for contact.
 *
 * Legacy id: {tenantId}_{name} → user / self.
 */
class CatalogScope
{
    public const KIND_USER = 'user';
    public const KIND_TENANT_SHARED = 'tenant-shared';
    public const KIND_MEMBERSHIP = 'membership';
    public const KIND_CONTACT = 'contact';
    public const KIND_CRM_GLOBAL = 'crm-global';

    /**
     * @return list<string>
     */
    public static function kinds(): array
    {
        return [
            self::KIND_USER,
            self::KIND_TENANT_SHARED,
            self::KIND_MEMBERSHIP,
            self::KIND_CONTACT,
            self::KIND_CRM_GLOBAL,
        ];
    }

    /**
     * @return array{
     *   workspaceKind: string,
     *   tenantId: ?string,
     *   targetUserId: ?string,
     *   membershipId: ?string,
     *   contactId: ?string,
     *   chatwootAccountCrmId: ?string,
     *   entityRef: string
     * }
     * @throws BadRequest
     */
    public static function fromRequestData(stdClass $data, User $user): array
    {
        $kind = self::normalizeKind(
            isset($data->workspaceKind) ? (string) $data->workspaceKind : self::KIND_USER
        );

        $tenantId = null;
        if (isset($data->tenantId) && is_string($data->tenantId) && $data->tenantId !== '') {
            $tenantId = $data->tenantId;
        } elseif (isset($data->tenant) && is_string($data->tenant) && $data->tenant !== '') {
            $tenantId = $data->tenant;
        }

        if ($kind !== self::KIND_CRM_GLOBAL && ($tenantId === null || $tenantId === '')) {
            throw new BadRequest('tenant (or tenantId) is required.');
        }

        $targetUserId = isset($data->targetUserId) && is_string($data->targetUserId) && $data->targetUserId !== ''
            ? $data->targetUserId
            : (isset($data->userId) && is_string($data->userId) && $data->userId !== ''
                ? $data->userId
                : null);

        $membershipId = isset($data->membershipId) && is_string($data->membershipId) && $data->membershipId !== ''
            ? $data->membershipId
            : null;

        $contactId = isset($data->contactId) && is_string($data->contactId) && $data->contactId !== ''
            ? $data->contactId
            : null;

        $chatwootAccountCrmId = isset($data->chatwootAccountCrmId) && is_string($data->chatwootAccountCrmId) && $data->chatwootAccountCrmId !== ''
            ? $data->chatwootAccountCrmId
            : null;

        if ($kind === self::KIND_USER && $targetUserId === null) {
            $targetUserId = $user->getId();
        }

        if ($kind === self::KIND_MEMBERSHIP && ($membershipId === null || $membershipId === '')) {
            throw new BadRequest('membershipId is required for membership catalog.');
        }

        if ($kind === self::KIND_CONTACT) {
            if ($contactId === null || $chatwootAccountCrmId === null) {
                throw new BadRequest('contactId and chatwootAccountCrmId are required for contact catalog.');
            }
        }

        $entityRef = self::buildEntityRef($kind, $targetUserId, $membershipId, $contactId, $chatwootAccountCrmId);

        return [
            'workspaceKind' => $kind,
            'tenantId' => $tenantId,
            'targetUserId' => $targetUserId,
            'membershipId' => $membershipId,
            'contactId' => $contactId,
            'chatwootAccountCrmId' => $chatwootAccountCrmId,
            'entityRef' => $entityRef,
        ];
    }

    /**
     * @param array{
     *   workspaceKind?: string|null,
     *   targetUserId?: string|null,
     *   membershipId?: string|null,
     *   contactId?: string|null,
     *   chatwootAccountCrmId?: string|null
     * } $opts
     * @return array<string, string>
     * @throws BadRequest
     */
    public static function toBackendQuery(string $tenantId, array $opts, User $user): array
    {
        $kind = self::normalizeKind($opts['workspaceKind'] ?? self::KIND_USER);

        $query = [
            'workspaceKind' => $kind,
        ];

        if ($kind !== self::KIND_CRM_GLOBAL) {
            $query['crmTenantId'] = $tenantId;
        }

        if ($kind === self::KIND_USER) {
            $target = $opts['targetUserId'] ?? null;
            if (!is_string($target) || $target === '') {
                $target = $user->getId();
            }
            $query['targetUserId'] = $target;
        }

        if ($kind === self::KIND_MEMBERSHIP) {
            $mid = $opts['membershipId'] ?? null;
            if (!is_string($mid) || $mid === '') {
                throw new BadRequest('membershipId is required for membership catalog.');
            }
            $query['membershipId'] = $mid;
        }

        if ($kind === self::KIND_CONTACT) {
            $cid = $opts['contactId'] ?? null;
            $aid = $opts['chatwootAccountCrmId'] ?? null;
            if (!is_string($cid) || $cid === '' || !is_string($aid) || $aid === '') {
                throw new BadRequest('contactId and chatwootAccountCrmId are required for contact catalog.');
            }
            $query['contactId'] = $cid;
            $query['chatwootAccountCrmId'] = $aid;
        }

        return $query;
    }

    /**
     * @return array{
     *   tenantId: string,
     *   workspaceKind: string,
     *   entityRef: string,
     *   name: string,
     *   targetUserId: ?string,
     *   membershipId: ?string,
     *   contactId: ?string,
     *   chatwootAccountCrmId: ?string
     * }
     * @throws BadRequest
     */
    public static function parseCompositeId(string $id): array
    {
        if (str_contains($id, '::')) {
            $parts = explode('::', $id);
            if (count($parts) !== 4) {
                throw new BadRequest(
                    "Invalid catalog id. Expected '{tenantId}::{workspaceKind}::{entityRef}::{name}'."
                );
            }

            [$tenantId, $kindRaw, $entityRef, $name] = $parts;
            $kind = self::normalizeKind($kindRaw);

            if ($tenantId === '' || $name === '') {
                throw new BadRequest('Invalid catalog id parts.');
            }

            $parsed = self::parseEntityRef($kind, $entityRef);

            return [
                'tenantId' => $tenantId,
                'workspaceKind' => $kind,
                'entityRef' => $entityRef !== '' ? $entityRef : '-',
                'name' => $name,
                'targetUserId' => $parsed['targetUserId'],
                'membershipId' => $parsed['membershipId'],
                'contactId' => $parsed['contactId'],
                'chatwootAccountCrmId' => $parsed['chatwootAccountCrmId'],
            ];
        }

        // Legacy: {tenantId}_{name} → user self
        $pos = strpos($id, '_');
        if ($pos === false || $pos === 0 || $pos === strlen($id) - 1) {
            throw new BadRequest("Invalid catalog id. Expected '{tenantId}_{name}' or scoped form.");
        }

        return [
            'tenantId' => substr($id, 0, $pos),
            'workspaceKind' => self::KIND_USER,
            'entityRef' => '-',
            'name' => substr($id, $pos + 1),
            'targetUserId' => null,
            'membershipId' => null,
            'contactId' => null,
            'chatwootAccountCrmId' => null,
        ];
    }

    public static function buildCompositeId(
        string $tenantId,
        string $workspaceKind,
        string $entityRef,
        string $name
    ): string {
        $kind = self::normalizeKind($workspaceKind);
        $ref = $entityRef !== '' ? $entityRef : '-';

        return $tenantId . '::' . $kind . '::' . $ref . '::' . $name;
    }

    /**
     * @throws BadRequest
     */
    public static function normalizeKind(string $raw): string
    {
        $k = strtolower(trim($raw));
        if ($k === 'shared' || $k === 'tenant') {
            $k = self::KIND_TENANT_SHARED;
        }
        if ($k === 'global') {
            $k = self::KIND_CRM_GLOBAL;
        }
        if ($k === 'tenant-user') {
            $k = self::KIND_USER;
        }
        if ($k === 'chatwoot-contact') {
            $k = self::KIND_CONTACT;
        }

        if (!in_array($k, self::kinds(), true)) {
            throw new BadRequest(
                'workspaceKind must be one of: user, tenant-shared, membership, contact, crm-global.'
            );
        }

        return $k;
    }

    private static function buildEntityRef(
        string $kind,
        ?string $targetUserId,
        ?string $membershipId,
        ?string $contactId,
        ?string $chatwootAccountCrmId
    ): string {
        return match ($kind) {
            self::KIND_USER => $targetUserId ?: '-',
            self::KIND_MEMBERSHIP => $membershipId ?: '-',
            self::KIND_CONTACT => ($chatwootAccountCrmId ?: '') . '~' . ($contactId ?: ''),
            default => '-',
        };
    }

    /**
     * @return array{
     *   targetUserId: ?string,
     *   membershipId: ?string,
     *   contactId: ?string,
     *   chatwootAccountCrmId: ?string
     * }
     */
    private static function parseEntityRef(string $kind, string $entityRef): array
    {
        $empty = [
            'targetUserId' => null,
            'membershipId' => null,
            'contactId' => null,
            'chatwootAccountCrmId' => null,
        ];

        if ($entityRef === '' || $entityRef === '-') {
            return $empty;
        }

        if ($kind === self::KIND_USER) {
            $empty['targetUserId'] = $entityRef;
        } elseif ($kind === self::KIND_MEMBERSHIP) {
            $empty['membershipId'] = $entityRef;
        } elseif ($kind === self::KIND_CONTACT && str_contains($entityRef, '~')) {
            [$aid, $cid] = explode('~', $entityRef, 2);
            $empty['chatwootAccountCrmId'] = $aid !== '' ? $aid : null;
            $empty['contactId'] = $cid !== '' ? $cid : null;
        }

        return $empty;
    }
}
