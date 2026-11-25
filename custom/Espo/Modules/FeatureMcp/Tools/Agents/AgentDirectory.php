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

namespace Espo\Modules\FeatureMcp\Tools\Agents;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ReadParams;
use Espo\Core\Record\ServiceFactory;
use Espo\Core\Select\SearchParams;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/** The AI agents (ChatwootAccountUserMembership with isAI) the user can see, read through their record service. */
class AgentDirectory
{
    private const int MAX = 100;

    /** @var ?list<array{id: string, name: string, workspace: string, tenantId: ?string, categoryIds: string[]}> */
    private ?array $agents = null;

    public function __construct(
        private ServiceFactory $serviceFactory,
        private User $user,
        private EntityManager $entityManager,
    ) {}

    /** @return list<array{id: string, name: string, workspace: string, tenantId: ?string, categoryIds: string[]}> */
    public function list(): array
    {
        if ($this->agents !== null) {
            return $this->agents;
        }

        $service = $this->serviceFactory->createForUser('ChatwootAccountUserMembership', $this->user);
        $rows = $service->find(SearchParams::fromRaw([
            'where' => [['type' => 'isTrue', 'attribute' => 'isAI']],
            'select' => ['id'],
            'maxSize' => self::MAX,
            'orderBy' => 'name',
            'order' => 'asc',
        ]))->getCollection();

        $this->agents = [];

        foreach ($rows as $row) {
            try {
                $agent = $service->read($row->getId(), ReadParams::create())->getEntity();
            } catch (NotFound|Forbidden) {
                continue;
            }

            $account = $this->entityManager->getEntityById('ChatwootAccount', (string) $agent->get('chatwootAccountId'));
            $this->agents[] = [
                'id' => $agent->getId(),
                'name' => (string) $agent->get('name'),
                'workspace' => (string) ($agent->get('chatwootAccountName') ?? $account?->get('name')),
                'tenantId' => $account?->get('tenantId'),
                'categoryIds' => $agent->getLinkMultipleIdList('knowledgeBaseCategories'),
            ];
        }

        return $this->agents;
    }
}
