<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityThreadState;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Executor\QueryExecutor;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use PDO;
use PHPUnit\Framework\TestCase;

class OpportunityThreadStateTest extends TestCase
{
    public function testSummariesResolveVirtualParticipantNamesWithoutGroupingByThem(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for the disposable query dataset.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
        $defs = [];
        foreach ([
            'Note' => ['id', 'parentType', 'parentId', 'type', 'createdById', 'createdAt', 'number',
                'opportunityThreadRootId', 'deleted'],
            'User' => ['id', 'firstName', 'lastName', 'deleted'],
            'OpportunityThreadReadState' => ['id', 'rootNoteId', 'userId', 'lastSeenNumber', 'deleted'],
        ] as $type => $fields) {
            $columns = [];
            foreach ($fields as $field) {
                $column = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
                $numeric = in_array($field, ['number', 'lastSeenNumber', 'deleted']);
                $columns[] = "$column " . ($numeric ? 'INTEGER DEFAULT 0' : 'TEXT');
                $defs[$type]['attributes'][$field] = ['type' => $numeric ? 'int' : 'varchar'];
            }
            $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $type));
            $pdo->exec("CREATE TABLE $table (" . implode(', ', $columns) . ')');
        }
        // Neither note.created_by_name nor user.name is a stored column in EspoCRM.
        $defs['User']['attributes']['name'] = [
            'type' => 'varchar',
            'notStorable' => true,
            'select' => ['select' => "CONCAT:(firstName, ' ', lastName)"],
        ];
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn($defs);
        $metadata = new Metadata($provider);
        $factory = $this->createMock(EntityFactory::class);
        $factory->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $defs[$type]));
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('me');
        $pdo->exec("INSERT INTO user (id, first_name, last_name) VALUES
            ('alice', 'Same', 'Name'), ('bob', 'Same', 'Name'), ('carol', 'Carol', 'Example'), ('me', 'My', 'Name')");
        $insert = $pdo->prepare('INSERT INTO note
            (id, parent_type, parent_id, type, created_by_id, created_at, number, opportunity_thread_root_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (['alice', 'bob', 'carol', 'me', 'alice'] as $index => $author) {
            $insert->execute(["reply-$index", 'Opportunity', 'opp', 'Post', $author,
                "2026-09-16 10:00:0$index", $index + 1, 'root-a']);
        }
        $insert->execute(['missing-author', 'Opportunity', 'opp', 'Post', 'missing',
            '2026-09-16 11:00:00', 6, 'root-b']);

        foreach ([new MysqlQueryComposer($pdo, $factory, $metadata), new PostgresqlQueryComposer($pdo, $factory, $metadata)] as $composer) {
            $pdo->exec('DELETE FROM opportunity_thread_read_state');
            $executor = $this->createMock(QueryExecutor::class);
            $executor->method('execute')->willReturnCallback(fn ($query) => $pdo->query($composer->composeSelect($query)));
            $entityManager = $this->createMock(EntityManager::class);
            $entityManager->method('getQueryExecutor')->willReturn($executor);
            $state = new OpportunityThreadState($entityManager, $user);

            $summaries = $state->summaries(['root-a', 'root-b', 'empty']);
            self::assertSame(5, $summaries['root-a']['replyCount']);
            self::assertSame(4, $summaries['root-a']['unreadCount']);
            self::assertSame('2026-09-16 10:00:04', $summaries['root-a']['lastReplyAt']);
            self::assertSame([
                ['id' => 'alice', 'name' => 'Same Name'],
                ['id' => 'me', 'name' => 'My Name'],
                ['id' => 'carol', 'name' => 'Carol Example'],
            ], $summaries['root-a']['participants']);
            self::assertSame([['id' => 'missing', 'name' => null]], $summaries['root-b']['participants']);
            self::assertSame(['replyCount' => 0, 'participants' => [], 'lastReplyAt' => null, 'unreadCount' => 0], $summaries['empty']);

            $pdo->exec("INSERT INTO opportunity_thread_read_state (id, root_note_id, user_id, last_seen_number)
                VALUES ('seen', 'root-a', 'me', 5)");
            $summaries = $state->summaries(['root-a', 'root-b']);
            self::assertSame(0, $summaries['root-a']['unreadCount']);
            self::assertSame(1, $summaries['root-b']['unreadCount']);
        }
    }
}
