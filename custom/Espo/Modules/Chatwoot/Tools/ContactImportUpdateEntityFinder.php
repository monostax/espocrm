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

namespace Espo\Modules\Chatwoot\Tools;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Json;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;
use Espo\Tools\Import\UpdateEntityFinder;
use stdClass;

/**
 * Resolves Contact import update keys backed by ContactChannelIdentity rows.
 */
class ContactImportUpdateEntityFinder implements UpdateEntityFinder
{
    /**
     * Import update-by helpers backed by ContactChannelIdentity.
     * Multiple WhatsApp columns map to the same channel and are OR-matched
     * (a contact matching any provided number is a candidate).
     */
    private const FIELD_CHANNEL_MAP = [
        'whatsappNumber' => 'whatsapp',
        'whatsappNumber2' => 'whatsapp',
        'whatsappNumber3' => 'whatsapp',
        'whatsappNumber4' => 'whatsapp',
        'instagramHandle' => 'instagram',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private AclManager $aclManager,
        private TenantResolver $tenantResolver,
        private UserTenantResolver $userTenantResolver,
    ) {}

    /**
     * @param array<string, mixed> $whereClause
     */
    public function find(array $whereClause, User $user, stdClass $values): ?CoreEntity
    {
        /** @var array<string, string[]> $identityValues */
        $identityValues = [];

        foreach (self::FIELD_CHANNEL_MAP as $field => $channelType) {
            if (!array_key_exists($field, $whereClause)) {
                continue;
            }

            $raw = trim((string) $whereClause[$field]);
            unset($whereClause[$field]);

            if ($raw === '') {
                continue;
            }

            $identityValues[$channelType] ??= [];
            $identityValues[$channelType][] = $raw;
        }

        if ($identityValues === []) {
            $entity = $this->entityManager
                ->getRDBRepository('Contact')
                ->where($whereClause)
                ->findOne();

            return $entity instanceof CoreEntity ? $entity : null;
        }

        $tenantId = $this->resolveTenantId($user, $values);

        $contactIds = null;

        // Across channels: AND (e.g. whatsapp + instagram must all match).
        // Within a channel's multi columns: OR (any listed number matches).
        foreach ($identityValues as $channelType => $valuesForChannel) {
            $matchedIds = [];

            foreach (array_values(array_unique($valuesForChannel)) as $value) {
                foreach ($this->findContactIds($tenantId, $channelType, $value) as $id) {
                    $matchedIds[$id] = true;
                }
            }

            $matchedIds = array_keys($matchedIds);

            $contactIds = $contactIds === null
                ? $matchedIds
                : array_values(array_intersect($contactIds, $matchedIds));

            if ($contactIds === []) {
                return null;
            }
        }

        if (array_key_exists('id', $whereClause)) {
            $explicitIds = is_array($whereClause['id'])
                ? $whereClause['id']
                : [$whereClause['id']];

            $contactIds = array_values(array_intersect($contactIds, $explicitIds));

            if ($contactIds === []) {
                return null;
            }
        }

        $whereClause['id'] = $contactIds;

        $contacts = $this->entityManager
            ->getRDBRepository('Contact')
            ->where($whereClause)
            ->find();

        $first = null;
        $editable = [];

        foreach ($contacts as $contact) {
            if (!$contact instanceof CoreEntity) {
                continue;
            }

            $first ??= $contact;

            if ($user->isSystem() || $this->aclManager->checkEntityEdit($user, $contact)) {
                $editable[] = $contact;
            }
        }

        if (count($editable) > 1) {
            throw new BadRequest(
                'Import update key matches multiple editable contacts. Add another update-by field to disambiguate.'
            );
        }

        return $editable[0] ?? $first;
    }

    /**
     * @return string[]
     */
    private function findContactIds(string $tenantId, string $channelType, string $value): array
    {
        $where = [
            'tenantId' => $tenantId,
            'channelType' => $channelType,
        ];

        if ($channelType === 'whatsapp') {
            $sourceId = PhoneNormalizer::normalize($value);

            if (!$sourceId) {
                throw new BadRequest("Invalid phone number '{$value}' for WhatsApp import matching.");
            }

            $where['sourceId'] = $sourceId;
        } else {
            $sourceId = ContactReconciler::normalizeHandle($value);

            if (!$sourceId) {
                throw new BadRequest("Invalid Instagram handle '{$value}' for import matching.");
            }

            $where['OR'] = [
                ['sourceId' => $sourceId],
                ['handle' => $sourceId],
            ];
        }

        $identities = $this->entityManager
            ->getRDBRepository('ContactChannelIdentity')
            ->select(['contactId'])
            ->where($where)
            ->find();

        $ids = [];

        foreach ($identities as $identity) {
            $contactId = $identity->get('contactId');

            if (is_string($contactId) && $contactId !== '') {
                $ids[$contactId] = true;
            }
        }

        return array_keys($ids);
    }

    private function resolveTenantId(User $user, stdClass $values): string
    {
        $allowedTenantIds = $this->userTenantResolver->resolveTenantIds($user);
        $requestedTenantId = $this->normalizeString($values->tenantId ?? null);
        $requestedTeamIds = $this->normalizeTeamIds($values->teamsIds ?? null);
        $teamTenantId = null;

        if ($requestedTeamIds !== []) {
            $requestedTenantIds = $this->findTenantIdsForTeams($requestedTeamIds);

            if (count($requestedTenantIds) !== 1) {
                throw new BadRequest('Import target teams must resolve to exactly one tenant.');
            }

            $teamTenantId = $requestedTenantIds[0];
        }

        if ($requestedTenantId) {
            if ($teamTenantId && $teamTenantId !== $requestedTenantId) {
                throw new BadRequest('Import target tenant conflicts with the target teams.');
            }

            if (
                !$user->isAdmin() &&
                !$user->isSystem() &&
                !in_array($requestedTenantId, $allowedTenantIds, true)
            ) {
                throw new BadRequest('Import target tenant is not available to the importing user.');
            }

            if (!$this->entityManager->getEntityById('Tenant', $requestedTenantId)) {
                throw new BadRequest('Import target tenant does not exist.');
            }

            return $requestedTenantId;
        }

        if ($teamTenantId) {
            if (
                !$user->isAdmin() &&
                !$user->isSystem() &&
                !in_array($teamTenantId, $allowedTenantIds, true)
            ) {
                throw new BadRequest('Import target tenant is not available to the importing user.');
            }

            return $teamTenantId;
        }

        $defaultTeamId = $user->get('defaultTeamId');
        $teamIds = is_string($defaultTeamId) && $defaultTeamId !== ''
            ? [$defaultTeamId]
            : $user->getTeamIdList();

        $tenantIds = $this->findTenantIdsForTeams($teamIds);

        if (count($tenantIds) !== 1) {
            throw new BadRequest(
                'Import matching by channel identity requires one target tenant or resolvable default tenant.'
            );
        }

        return $tenantIds[0];
    }

    /**
     * @param string[] $teamIds
     * @return string[]
     */
    private function findTenantIdsForTeams(array $teamIds): array
    {
        return $this->tenantResolver->resolveAllFromTeamIds(array_values($teamIds));
    }

    /** @return string[] */
    private function normalizeTeamIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return [];
            }

            $value = str_starts_with($value, '[')
                ? Json::decode($value)
                : explode(',', $value);
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn (mixed $item): ?string => $this->normalizeString($item),
                $value,
            )));
        }

        return [];
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
