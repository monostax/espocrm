<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Rebuild;

use Espo\Modules\Global\Rebuild\MigrateTaskPlannedStatus;
use Espo\ORM\Defs\Defs;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

class MigrateTaskPlannedStatusTest extends TestCase
{
    public function testMigratesTasksAndSavedActionsIdempotently(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE task (id TEXT, status TEXT, deleted INTEGER)');
        $pdo->exec("INSERT INTO task VALUES ('pending', 'Not Started', 0), ('deleted', 'Not Started', 1), ('done', 'Completed', 0), ('planned', 'Planned', 0)");
        $pdo->exec('CREATE TABLE journey_stage_action (id TEXT, type TEXT, params TEXT)');
        $pdo->exec('CREATE TABLE automation (id TEXT, definition TEXT)');
        $params = json_encode(['status' => 'Not Started', 'name' => 'Not Started', 'options' => (object) []]);
        $insert = $pdo->prepare('INSERT INTO journey_stage_action VALUES (?, ?, ?)');
        $insert->execute(['task', 'createTask', $params]);
        $insert->execute(['other', 'sendEmail', $params]);
        $definition = (object) ['stages' => [(object) ['actions' => [
            (object) ['type' => 'createTask', 'params' => json_decode($params)],
            (object) ['type' => 'sendEmail', 'params' => json_decode($params)],
        ]]]];
        $pdo->prepare('INSERT INTO automation VALUES (?, ?)')->execute(['automation', json_encode($definition)]);

        $defs = $this->createMock(Defs::class);
        $defs->method('hasEntity')->willReturn(true);
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        $em->method('getDefs')->willReturn($defs);
        $migration = new MigrateTaskPlannedStatus($em);

        $snapshot = null;
        for ($run = 0; $run < 2; $run++) {
            $migration->process();
            $this->assertSame(['Planned', 'Planned', 'Completed', 'Planned'], $pdo->query('SELECT status FROM task ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN));
            $actions = $pdo->query('SELECT id, params FROM journey_stage_action ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->assertSame($params, $actions['other']);
            $task = json_decode($actions['task']);
            $this->assertSame('Planned', $task->status);
            $this->assertSame('Not Started', $task->name);
            $this->assertInstanceOf(\stdClass::class, $task->options);
            $stored = $pdo->query('SELECT definition FROM automation')->fetchColumn();
            $automation = json_decode($stored);
            $this->assertSame('Planned', $automation->stages[0]->actions[0]->params->status);
            $this->assertSame('Not Started', $automation->stages[0]->actions[1]->params->status);
            if ($run === 1) $this->assertSame($snapshot, [$actions, $stored]);
            $snapshot = [$actions, $stored];
        }
    }
}
