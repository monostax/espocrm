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

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\ORM\DatabaseParamsFactory;
use Espo\Core\ORM\PDO\PDOFactoryFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Query;
use PDO;

/**
 * Remote resources created for a new ChatwootAccount, recorded the moment
 * Chatwoot returns them.
 *
 * Creating a ChatwootAccount creates a remote account and a concierge user
 * before the CRM row is inserted. When a later step fails, the remote
 * resources already exist. A retry with the same ChatwootAccount id reads
 * this record and reuses them instead of creating a second account.
 *
 * The record id is the ChatwootAccount id. The save that runs the hook is
 * inside a transaction (FeatureLiveRecords wraps every save), which a failure
 * rolls back. So the journal reads and writes on its own connection, where
 * each statement commits at once.
 */
class ChatwootAccountCreationJournal
{
    private const ENTITY_TYPE = 'ChatwootAccountCreation';

    private ?PDO $connection = null;

    public function __construct(
        private EntityManager $entityManager,
        private DatabaseParamsFactory $databaseParamsFactory,
        private PDOFactoryFactory $pdoFactoryFactory,
    ) {}

    /**
     * @return array{remoteAccountId: ?int, conciergeUserId: ?int, conciergeEmail: ?string}|null
     */
    public function find(string $accountId): ?array
    {
        $row = $this->connection()->query($this->sql(
            $this->entityManager
                ->getQueryBuilder()
                ->select(['remoteAccountId', 'conciergeUserId', 'conciergeEmail'])
                ->from(self::ENTITY_TYPE)
                ->where(['id' => $accountId])
                ->build()
        ))->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'remoteAccountId' => isset($row['remoteAccountId']) ? (int) $row['remoteAccountId'] : null,
            'conciergeUserId' => isset($row['conciergeUserId']) ? (int) $row['conciergeUserId'] : null,
            'conciergeEmail' => $row['conciergeEmail'] ?? null,
        ];
    }

    public function recordAccount(string $accountId, int $remoteAccountId): void
    {
        $this->write($accountId, ['remoteAccountId' => $remoteAccountId]);
    }

    public function recordConcierge(string $accountId, int $userId, string $email): void
    {
        $this->write($accountId, ['conciergeUserId' => $userId, 'conciergeEmail' => $email]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function write(string $accountId, array $values): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $builder = $this->entityManager->getQueryBuilder();

        if ($this->find($accountId) === null) {
            $columns = ['id', ...array_keys($values), 'createdAt', 'modifiedAt'];
            $query = $builder
                ->insert()
                ->into(self::ENTITY_TYPE)
                ->columns($columns)
                ->values(['id' => $accountId, ...$values, 'createdAt' => $now, 'modifiedAt' => $now])
                ->build();
        } else {
            $query = $builder
                ->update()
                ->in(self::ENTITY_TYPE)
                ->set([...$values, 'modifiedAt' => $now])
                ->where(['id' => $accountId])
                ->build();
        }

        $this->connection()->exec($this->sql($query));
    }

    private function sql(Query $query): string
    {
        return $this->entityManager->getQueryComposer()->compose($query);
    }

    private function connection(): PDO
    {
        if (!$this->connection) {
            $params = $this->databaseParamsFactory->create();
            $this->connection = $this->pdoFactoryFactory->create($params->getPlatform() ?? 'Mysql')->create($params);
        }

        return $this->connection;
    }
}
