<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityEventAccess;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** A task's Mural shows its own overdue event, unread only for the people it is addressed to. */
class ActivityDiscussionTaskOverdueTest extends TestCase
{
    private PDO $pdo;
    private array $composers;
    private ActivityDiscussion $discussion;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for the disposable query dataset.');
        }
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $defs = [];
        foreach ([
            'Note' => ['id', 'parentType', 'parentId', 'relatedType', 'relatedId', 'type', 'number', 'createdById',
                'opportunityMentionUserIds', 'opportunityThreadRootId', 'deleted'],
            'ActivityReadState' => ['id', 'parentType', 'parentId', 'userId', 'threadKey', 'lastSeenNumber', 'deleted'],
        ] as $type => $fields) {
            $columns = [];
            foreach ($fields as $field) {
                $numeric = in_array($field, ['number', 'lastSeenNumber', 'deleted'], true);
                $defs[$type]['attributes'][$field] = ['type' => $numeric ? 'int' : 'varchar'];
                $column = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
                $columns[] = "$column " . ($numeric ? 'INTEGER' : 'TEXT') . ($field === 'deleted' ? ' DEFAULT 0' : '');
            }
            $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $type));
            $this->pdo->exec("CREATE TABLE $table (" . implode(', ', $columns) . ')');
        }
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn($defs);
        $entities = $this->createMock(EntityFactory::class);
        $entities->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $defs[$type]));
        $metadata = new Metadata($provider);
        $this->composers = [new MysqlQueryComposer($this->pdo, $entities, $metadata),
            new PostgresqlQueryComposer($this->pdo, $entities, $metadata)];
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('me');
        $this->discussion = new ActivityDiscussion(
            $this->createMock(EntityManager::class), $user, $this->createMock(Acl::class),
            $this->createMock(OpportunityEventAccess::class),
        );

        $overdue = OpportunityStreamEvents::ACTIVITY_OVERDUE;
        foreach ([
            ['post', 'Task', 't1', null, null, 'Post', 1, 'colleague', null],
            ['mention', 'Task', 't1', null, null, 'Post', 2, 'colleague', '["me"]'],
            ['own-overdue', 'Task', 't1', 'Task', 't1', $overdue, 3, 'system', '["me","boss"]'],
            ['others-overdue', 'Task', 't1', 'Task', 't1', $overdue, 4, 'system', '["boss"]'],
            ['foreign-overdue', 'Task', 't1', 'Task', 't2', $overdue, 5, 'system', '["me"]'],
            ['message', 'Task', 't1', 'ChatwootConversation', 'c1', OpportunityStreamEvents::MESSAGE_RECEIVED, 6, 'system', '["me"]'],
            ['deal-overdue', 'Opportunity', 'o1', 'Task', 't1', $overdue, 7, 'system', null],
        ] as $row) {
            $this->pdo->prepare('INSERT INTO note (id, parent_type, parent_id, related_type, related_id, type, number, created_by_id,
                opportunity_mention_user_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($row);
        }
    }

    private function ids(array ...$wheres): array
    {
        $builder = SelectBuilder::create()->from('Note')->select('id')
            ->where(['parentType' => 'Task', 'parentId' => 't1', 'opportunityThreadRootId' => null])->order('number');
        foreach ($wheres as $where) $builder->where($where);
        $query = $builder->build();
        $result = null;
        foreach ($this->composers as $composer) {
            $sql = $composer->composeSelect($query);
            // SQLite has no ILIKE; its LIKE is already case-insensitive.
            $ids = $this->pdo->query(str_replace(' ILIKE ', ' LIKE ', $sql))->fetchAll(PDO::FETCH_COLUMN);
            $result ??= $ids;
            self::assertSame($result, $ids, $sql);
        }
        return $result;
    }

    public function testTaskMuralShowsPostsAndOnlyItsOwnOverdueEvent(): void
    {
        self::assertSame(['post', 'mention', 'own-overdue', 'others-overdue'], $this->ids($this->discussion->entryWhere('Task')));
        // Threads stay posts only.
        self::assertSame(['post', 'mention'], $this->ids($this->discussion->entryWhere('Task', 'root')));
    }

    public function testOverdueEventIsUnreadOnlyForItsRecipients(): void
    {
        $counts = (new ReflectionMethod($this->discussion, 'countsAsUnreadWhere'))->invoke($this->discussion, 'Task');
        self::assertSame(['mention', 'own-overdue'], $this->ids(
            $this->discussion->entryWhere('Task'), $this->discussion->unreadWhere(), $counts,
        ));
        $this->pdo->exec("INSERT INTO activity_read_state (id, parent_type, parent_id, user_id, thread_key, last_seen_number)
            VALUES ('seen', 'Task', 't1', 'me', 'main', 3)");
        self::assertSame([], $this->ids($this->discussion->entryWhere('Task'), $this->discussion->unreadWhere(), $counts));
    }

    public function testMeetingsKeepPostsOnly(): void
    {
        $where = $this->discussion->entryWhere('Meeting');
        self::assertSame(['type' => 'Post'], $where);
    }
}
