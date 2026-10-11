<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Accounting\ReservationInput;
use Espo\Modules\FeatureCredits\Accounting\SourceReference;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

trait SourceAdmissionCases
{
    #[DataProvider('dialects')]
    public function testSignedOriginsPersistBeforeTelemetryAndSurvivePublicationFailure(string $dialect): void
    {
        $this->executionDatabase($dialect, function (PDO $pdo): void {
            $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input());
            $this->cutovers($pdo)->schedule(new \Espo\Modules\FeatureCredits\Accounting\CutoverInput('tenant', $this->now, (object) ['approved' => true]));
            $pdo->exec('CREATE TABLE chatwoot_account (id VARCHAR(24) PRIMARY KEY, tenant_id VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            $pdo->exec('CREATE TABLE chatwoot_conversation (id VARCHAR(24) PRIMARY KEY, chatwoot_account_id VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            $pdo->exec('CREATE TABLE chatwoot_account_user_membership (id VARCHAR(24) PRIMARY KEY, chatwoot_account_id VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            $pdo->exec('CREATE TABLE opportunity (id VARCHAR(24) PRIMARY KEY, tenant_id VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            $pdo->exec('CREATE TABLE note (id VARCHAR(24) PRIMARY KEY, parent_type VARCHAR(64), parent_id VARCHAR(24), type VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            $pdo->exec('CREATE TABLE chatwoot_ai_agent_run (id VARCHAR(24) PRIMARY KEY, tenant_id VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            try {
                $pdo->exec("INSERT INTO chatwoot_account (id, tenant_id) VALUES ('account', 'tenant'), ('foreign-account', 'other')");
                $pdo->exec("INSERT INTO chatwoot_conversation (id, chatwoot_account_id) VALUES ('conversation', 'account'), ('foreign', 'foreign-account')");
                $pdo->exec("INSERT INTO chatwoot_account_user_membership (id, chatwoot_account_id) VALUES ('agent', 'account'), ('foreign', 'foreign-account')");
                $pdo->exec("INSERT INTO opportunity (id, tenant_id) VALUES ('deal', 'tenant'), ('foreign', 'other')");
                $pdo->exec("INSERT INTO note (id, parent_type, parent_id, type) VALUES ('post', 'Opportunity', 'deal', 'Post'), ('foreign', 'Opportunity', 'foreign', 'Post')");
                foreach (['ChatwootConversation' => 'conversation', 'Opportunity' => 'deal', 'Note' => 'post', 'ChatwootAccountUserMembership' => 'agent'] as $type => $id) {
                    $input = ExecutionFixtures::admission();
                    $input->runId = substr(hash('sha256', $type), 0, 17);
                    $input->workflowRunId = 'source-' . $type;
                    $input->source = (object) ['type' => $type, 'id' => 'foreign'];
                    $catalog = [$input->modelRateId => ExecutionFixtures::policy()];
                    try { $this->terminalCommand($pdo, $input, $catalog); $this->fail('Foreign source admitted.'); }
                    catch (Conflict) { $this->addToAssertionCount(1); }
                    $input->source->id = $id;
                    if ($type === 'ChatwootConversation') $input->source->requestId = str_repeat('a', 64);
                    $admitted = $this->terminalCommand($pdo, $input, $catalog);
                    $usageId = $admitted['receipt']['usageId'];
                    $query = $pdo->prepare('SELECT source_type, source_id, evidence FROM credit_usage WHERE id = ?');
                    $query->execute([$usageId]); $stored = $query->fetch(PDO::FETCH_ASSOC);
                    $this->assertSame($type, $stored['source_type']); $this->assertSame($id, $stored['source_id']);
                    if ($type === 'ChatwootConversation') $this->assertSame(['sourceRequestId' => str_repeat('a', 64)], json_decode($stored['evidence'], true));
                    $this->assertLinkedSourceNavigation($pdo, $type, $id, $stored['evidence']);
                    $this->assertSame($admitted, $this->terminalCommand($pdo, $input, $catalog));
                    $changed = clone $input; $changed->source = clone $input->source; $changed->source->id = 'foreign';
                    try { $this->terminalCommand($pdo, $changed, $catalog); $this->fail('Source changed on retry.'); }
                    catch (Conflict) { $this->addToAssertionCount(1); }
                    $authorize = clone $input; unset($authorize->source, $authorize->billingRateId);
                    $authorize->operation = 'authorize'; $authorize->usageId = $usageId; $authorize->requestKey = 'first';
                    $request = $this->terminalCommand($pdo, $authorize, $catalog);
                    $outcome = ExecutionFixtures::outcome();
                    $outcome->runId = $input->runId; $outcome->workflowRunId = $input->workflowRunId;
                    $outcome->usageId = $usageId; $outcome->requestId = $request['receipt']['requestId'];
                    $this->terminalCommand($pdo, $outcome);
                    $receipt = $this->terminalCommand($pdo, $this->terminalOnly($outcome, 'settle'));
                    // Publication/telemetry have not created any run row. Financial
                    // requests still join the original source via usage_id.
                    $query = $pdo->prepare('SELECT u.source_id FROM credit_request r JOIN credit_usage u ON u.id = r.usage_id AND u.tenant_id = r.tenant_id WHERE r.id = ?');
                    $query->execute([$outcome->requestId]); $this->assertSame($id, $query->fetchColumn());
                    $this->assertSame($receipt, $this->terminalCommand($pdo, $this->terminalOnly($outcome, 'settle')));
                    // A delayed or untrusted telemetry row is not an attribution
                    // writer. Its existence and tenant cannot redirect the origin.
                    $pdo->prepare('INSERT INTO chatwoot_ai_agent_run (id, tenant_id) VALUES (?, ?)')->execute([$input->runId, 'other']);
                    $query->execute([$outcome->requestId]); $this->assertSame($id, $query->fetchColumn());
                }
                $this->assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
                $this->assertHeld($pdo, '0.0000');
            } finally {
                foreach (['chatwoot_ai_agent_run', 'note', 'opportunity', 'chatwoot_account_user_membership', 'chatwoot_conversation', 'chatwoot_account'] as $table) $pdo->exec("DROP TABLE $table");
            }
        });
    }

    private function assertLinkedSourceNavigation(PDO $pdo, string $type, string $id, ?string $evidence): void
    {
        $entity = function (array $fields) {
            $record = $this->createStub(\Espo\ORM\Entity::class);
            $record->method('get')->willReturnCallback(fn ($field) => $fields[$field] ?? null);
            return $record;
        };
        $tenant = $entity([]);
        $usage = $entity(['tenantId' => 'tenant', 'sourceType' => $type, 'sourceId' => $id,
            'evidence' => $evidence === null ? null : json_decode($evidence)]);
        $source = $entity(['tenantId' => 'tenant', 'name' => 'Source', 'parentType' => 'Opportunity', 'parentId' => 'deal']);
        $parent = $entity(['tenantId' => 'tenant']);
        $manager = $this->createStub(\Espo\ORM\EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);
        $manager->method('getEntityById')->willReturnCallback(fn ($scope) => match ($scope) {
            'Tenant' => $tenant, 'CreditUsage' => $usage, $type => $source, default => $parent,
        });
        $mode = 'allowed';
        $acl = $this->createStub(\Espo\Core\Acl::class);
        $acl->method('checkScope')->willReturnCallback(function () use (&$mode) { return $mode !== 'scope-denied'; });
        // Capture mode by reference so each navigation rechecks current access.
        $acl->method('check')->willReturnCallback(function ($record) use (&$mode, $source) {
            return $record === $source ? $mode !== 'record-denied' : $mode !== 'parent-denied';
        });
        $acl->method('checkEntity')->willReturnCallback(function () use (&$mode) { return $mode !== 'stream-denied'; });
        $acl->method('checkField')->willReturnCallback(function () use (&$mode) { return $mode !== 'name-denied'; });
        $service = new \Espo\Modules\FeatureCredits\Services\OperationSource($manager, $acl);
        $allowed = $service->inspect('tenant', 'usage')['source'];
        $this->assertSame($id, $allowed['id']);
        if ($evidence !== null) $this->assertSame(str_repeat('a', 64), $allowed['requestId']);
        $mode = 'scope-denied'; $this->assertNull($service->inspect('tenant', 'usage')['source']);
        $mode = 'record-denied'; $this->assertNull($service->inspect('tenant', 'usage')['source']);
        if ($type === 'Note') {
            $mode = 'parent-denied'; $this->assertNull($service->inspect('tenant', 'usage')['source']);
            $mode = 'stream-denied'; $this->assertNull($service->inspect('tenant', 'usage')['source']);
        }
        $mode = 'name-denied'; $this->assertNull($service->inspect('tenant', 'usage')['source']['name']);
        if ($type === 'ChatwootConversation' || $type === 'Note') {
            $table = $type === 'Note' ? 'opportunity' : 'chatwoot_account';
            $pdo->exec("UPDATE $table SET tenant_id = 'other'");
            $this->assertNull($service->inspect('tenant', 'usage')['source']);
            $pdo->exec("UPDATE $table SET tenant_id = 'tenant' WHERE id = '" . ($type === 'Note' ? 'deal' : 'account') . "'");
        }
    }

    #[DataProvider('dialects')]
    public function testStreamOriginAllParentOwnershipContracts(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $quote = fn ($name) => $dialect === 'Mysql' ? "`$name`" : '"' . $name . '"';
            $pdo->exec('ALTER TABLE tenant ADD base_user_team_id VARCHAR(24)');
            $pdo->exec("UPDATE tenant SET base_user_team_id = 'base' WHERE id = 'tenant'");
            $tables = ['note', 'team', 'entity_team', 'tenant_other_user_team', 'opportunity', 'initiative', 'account', 'contact', 'ai_session', 'task', 'meeting', 'call'];
            try {
                $pdo->exec('CREATE TABLE note (id VARCHAR(24) PRIMARY KEY, parent_type VARCHAR(64), parent_id VARCHAR(24), type VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)');
                $pdo->exec('CREATE TABLE team (id VARCHAR(24) PRIMARY KEY, deleted BOOLEAN NOT NULL DEFAULT FALSE)');
                $pdo->exec('CREATE TABLE entity_team (id VARCHAR(24), entity_id VARCHAR(24), entity_type VARCHAR(64), team_id VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)');
                $pdo->exec('CREATE TABLE tenant_other_user_team (tenant_id VARCHAR(24), team_id VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)');
                $pdo->exec("INSERT INTO team (id) VALUES ('base'), ('additional'), ('foreign')");
                $pdo->exec("INSERT INTO tenant_other_user_team (tenant_id, team_id) VALUES ('tenant', 'additional')");
                foreach (['Opportunity' => 'opportunity', 'Initiative' => 'initiative', 'Account' => 'account', 'Contact' => 'contact', 'AiSession' => 'ai_session', 'Task' => 'task', 'Meeting' => 'meeting', 'Call' => 'call'] as $type => $table) {
                    $table = $quote($table);
                    $pdo->exec("CREATE TABLE $table (id VARCHAR(24) PRIMARY KEY, tenant_id VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)");
                    $pdo->exec("INSERT INTO $table (id, tenant_id) VALUES ('parent', 'tenant')");
                    $pdo->prepare('INSERT INTO note (id, parent_type, parent_id, type) VALUES (?, ?, ?, ?)')->execute([$type, $type, 'parent', 'Post']);
                    $pdo->prepare('INSERT INTO entity_team (id, entity_id, entity_type, team_id) VALUES (?, ?, ?, ?)')->execute([$type, 'parent', $type, 'additional']);
                    $source = new SourceReference('Note', $type);
                    $pdo->beginTransaction();
                    try {
                        $source->assertTenantLocked($pdo, 'tenant');
                        $this->assertFalse($source->belongsToTenant($pdo, 'other', true));
                    } finally { $pdo->rollBack(); }
                    $pdo->exec("UPDATE $table SET deleted = TRUE");
                    $this->assertFalse($source->belongsToTenant($pdo, 'tenant'));
                    $pdo->exec("UPDATE $table SET deleted = FALSE, tenant_id = 'other'");
                    if (!in_array($type, ['Task', 'Meeting'], true)) $this->assertFalse($source->belongsToTenant($pdo, 'tenant'));
                    if ($type === 'Call') {
                        $pdo->exec("UPDATE $table SET tenant_id = NULL");
                        $this->assertTrue($source->belongsToTenant($pdo, 'tenant'));
                    }
                    $pdo->prepare('UPDATE entity_team SET deleted = TRUE WHERE entity_type = ?')->execute([$type]);
                    $this->assertFalse($source->belongsToTenant($pdo, 'tenant'));
                }
            } finally {
                foreach (array_reverse($tables) as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $quote($table));
            }
        });
    }

    #[DataProvider('dialects')]
    public function testConcurrentSourceAdmissionReplayAndConflicts(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input());
            $pdo->exec('CREATE TABLE opportunity (id VARCHAR(24) PRIMARY KEY, tenant_id VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            try {
                $pdo->exec("INSERT INTO opportunity (id, tenant_id) VALUES ('one', 'tenant'), ('two', 'tenant')");
                $results = $this->race($pdo, $dialect, [
                    ['reserve-source', 'same', '1', 'one'], ['reserve-source', 'same', '1', 'one'],
                ]);
                $this->assertArrayHasKey('result', $results[0]);
                $this->assertSame($results[0], $results[1]);
                $this->assertHeld($pdo, '1.0000');
                $results = $this->race($pdo, $dialect, [
                    ['reserve-source', 'conflict', '1', 'one'], ['reserve-source', 'conflict', '1', 'two'],
                ]);
                $this->assertCount(1, array_column($results, 'result'));
                $this->assertSame([Conflict::class], array_values(array_column($results, 'error')));
                $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
                $this->assertHeld($pdo, '2.0000');
                $this->assertConsistent($pdo, '10.0000');
            } finally {
                $pdo->exec('DROP TABLE opportunity');
            }
        });
    }

    #[DataProvider('dialects')]
    public function testAdmissionSourceOwnershipReplayAndRollback(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            $service = $this->reservations($pdo);
            (new Funding($this->lock($pdo)))->grant($this->input());
            foreach (SourceReference::TABLES as $scope => $table) {
                $pdo->exec("CREATE TABLE $table (id VARCHAR(24) PRIMARY KEY, tenant_id VARCHAR(24), deleted BOOLEAN NOT NULL DEFAULT FALSE)");
                try {
                    $pdo->exec("INSERT INTO $table (id, tenant_id, deleted) VALUES
                        ('source', 'tenant', FALSE), ('foreign', 'other', FALSE), ('gone', 'tenant', TRUE), ('unknown', NULL, FALSE)");
                    foreach (['missing', 'foreign', 'gone', 'unknown'] as $id) {
                        try {
                            $service->reserve(new ReservationInput('tenant', 'ai', 'op', 'execution', 'rate', '1', new SourceReference($scope, $id)));
                            $this->fail('Invalid source accepted.');
                        } catch (Conflict) {
                            $this->assertHeld($pdo, '0.0000');
                            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
                        }
                    }
                    $input = new ReservationInput('tenant', 'ai', 'op', 'execution', 'rate', '1', new SourceReference($scope, 'source'));
                    $pdo->exec('ALTER TABLE credit_allocation ADD CONSTRAINT source_fault CHECK (1 = 0)');
                    try {
                        $service->reserve($input);
                        $this->fail('Injected failure ignored.');
                    } catch (PDOException) {
                        $this->assertHeld($pdo, '0.0000');
                        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_usage')->fetchColumn());
                    } finally {
                        $pdo->exec('ALTER TABLE credit_allocation DROP ' . ($dialect === 'Mysql' ? 'CHECK' : 'CONSTRAINT') . ' source_fault');
                    }
                    $receipt = $service->reserve($input);
                    $this->assertSame(['source_type' => $scope, 'source_id' => 'source'],
                        $pdo->query('SELECT source_type, source_id FROM credit_usage')->fetch(PDO::FETCH_ASSOC));
                    $this->assertSame($receipt, $service->reserve($input));
                    foreach ([null, new SourceReference($scope, 'foreign')] as $replacement) {
                        try {
                            $service->reserve(new ReservationInput('tenant', 'ai', 'op', 'execution', 'rate', '1', $replacement));
                            $this->fail('Source attribution was rewritten.');
                        } catch (Conflict) {
                            $this->assertHeld($pdo, '1.0000');
                        }
                    }
                    $pdo->exec("DELETE FROM $table WHERE id = 'source'");
                    $this->assertSame($receipt, $service->reserve($input), 'Deleted source must not break financial replay.');
                    $released = $service->release('tenant', $receipt['usageId'], 'execution');
                    $this->assertSame($released, $service->reserve($input));
                    $this->assertHeld($pdo, '0.0000');
                    // Clear only this fixture operation so both allowlisted tables exercise the same guards.
                    $pdo->exec('DELETE FROM credit_allocation');
                    $pdo->exec('DELETE FROM credit_reservation');
                    $pdo->exec('DELETE FROM credit_usage');
                } finally {
                    $pdo->exec("DROP TABLE $table");
                }
            }
            $legacy = $service->reserve($this->admission());
            $this->assertNull($pdo->query('SELECT source_id FROM credit_usage')->fetchColumn());
            $this->assertSame($legacy, $service->reserve($this->admission()));
            $this->assertConsistent($pdo, '10.0000');
        });
    }
}
