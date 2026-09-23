<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Reports;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilder as AccessBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item;
use Espo\Core\Utils\Language;
use Espo\Entities\User;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\Modules\Chatwoot\Reports\EpisodesByAgent;
use Espo\Modules\Chatwoot\Reports\EpisodesByTeam;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Executor\QueryExecutor;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

/** Real ORM SQL on disposable data; strict ACL and runtime filter inputs are controlled. */
class EpisodeParticipationTest extends TestCase
{
    private PDO $pdo;
    private EpisodesByAgent $agents;
    private EpisodesByTeam $teams;
    private User $user;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $defs = [];
        foreach ([
            'ChatwootConversationEpisode' => ['id', 'chatwootAccountId', 'inboxId', 'startedAt', 'lifecycleAssigneeNames', 'lifecycleTeamNames', 'readable', 'deleted'],
            'ChatwootAccount' => ['id', 'name', 'readable', 'deleted'],
            'Visibility' => ['id', 'episodeId', 'deleted'],
        ] as $type => $fields) {
            $columns = [];
            foreach ($fields as $field) {
                $numeric = in_array($field, ['readable', 'deleted']);
                $column = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
                $columns[] = "$column " . ($numeric ? 'INTEGER DEFAULT 0' : 'TEXT');
                $defs[$type]['attributes'][$field] = ['type' => $numeric ? 'bool' : 'varchar'];
            }
            $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $type));
            $this->pdo->exec('CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')');
        }
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn($defs);
        $entities = $this->createMock(EntityFactory::class);
        $entities->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $defs[$type]));
        $composer = new MysqlQueryComposer($this->pdo, $entities, new Metadata($provider));
        $em = $this->createMock(EntityManager::class);
        $executor = $this->createMock(QueryExecutor::class);
        $executor->method('execute')->willReturnCallback(fn ($query) => $this->pdo->query($composer->composeSelect($query)));
        $em->method('getQueryExecutor')->willReturn($executor);
        $repo = $this->createMock(RDBRepository::class);
        $em->method('getRDBRepository')->with('ChatwootConversationEpisode')->willReturn($repo);
        $repo->method('clone')->willReturnCallback(function ($query) use ($composer, $defs) {
            $selection = $this->createMock(RDBSelectBuilder::class);
            $selection->method('find')->willReturnCallback(function () use ($query, $composer, $defs) {
                $rows = $this->pdo->query($composer->composeSelect($query))->fetchAll(PDO::FETCH_ASSOC);
                return new EntityCollection(array_map(function ($row) use ($defs) {
                    $entity = new BaseEntity('ChatwootConversationEpisode', $defs['ChatwootConversationEpisode']);
                    $entity->set($row);
                    return $entity;
                }, $rows));
            });
            $selection->method('count')->willReturnCallback(function () use ($query, $composer) {
                $all = SelectBuilder::create()->clone($query)->limit(null, null)->build();
                return (int) $this->pdo->query('SELECT COUNT(*) FROM (' . $composer->composeSelect($all) . ') counted')->fetchColumn();
            });
            return $selection;
        });
        $this->user = $this->createMock(User::class);
        $factory = $this->createMock(SelectBuilderFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            $builder = $this->createMock(AccessBuilder::class);
            $type = null;
            $strict = false;
            $forUser = false;
            $params = SearchParams::create();
            $builder->method('from')->willReturnCallback(function ($value) use (&$type, $builder) {
                $type = $value;
                return $builder;
            });
            $builder->method('withStrictAccessControl')->willReturnCallback(function () use (&$strict, $builder) {
                $strict = true;
                return $builder;
            });
            $builder->method('forUser')->willReturnCallback(function ($user) use (&$forUser, $builder) {
                self::assertSame($this->user, $user);
                $forUser = true;
                return $builder;
            });
            $builder->method('withSearchParams')->willReturnCallback(function ($value) use (&$params, $builder) {
                $params = $value;
                return $builder;
            });
            $builder->method('buildQueryBuilder')->willReturnCallback(function () use (&$type, &$strict, &$forUser, &$params) {
                self::assertTrue($strict, 'Episode and account queries must apply strict ACL.');
                self::assertTrue($forUser, 'Queries must run as the requesting user.');
                $query = SelectBuilder::create()->from($type, lcfirst($type))->where(['readable' => 1])->select($params->getSelect() ?? ['*']);
                if ($type === 'ChatwootConversationEpisode') {
                    // A relationship filter duplicates episode a: both aggregate
                    // counts and paginated drill-down must remain distinct.
                    $query->join('Visibility', 'visibility', ['visibility.episodeId:' => 'chatwootConversationEpisode.id']);
                }
                if ($params->getWhere()) {
                    $this->applyWhere($query, $params->getWhere());
                }
                if ($params->getOrderBy()) {
                    $query->order($params->getOrderBy(), $params->getOrder() ?? 'ASC');
                }
                return $query->limit($params->getOffset(), $params->getMaxSize());
            });
            return $builder;
        });
        $language = $this->createMock(Language::class);
        $language->method('translateLabel')->willReturnArgument(0);
        $this->agents = new EpisodesByAgent($em, $factory, $language);
        $this->teams = new EpisodesByTeam($em, $factory, $language);
        $this->pdo->exec("INSERT INTO chatwoot_account (id, name, readable) VALUES ('A', 'Account A', 1), ('B', 'Hidden account name', 0)");
        $insert = $this->pdo->prepare('INSERT INTO chatwoot_conversation_episode VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            ['a', 'A', 'one', '2026-08-01', ['Alice', 'Alice', 'Bob, Jr.'], ['Support', 'Sales', 'Support'], 1, 0],
            ['b', 'A', 'two', '2026-08-02', ['Alice'], ['Sales'], 1, 0],
            ['c', 'B', 'one', '2026-08-03', ['Alice'], ['Support'], 1, 0],
            ['hidden', 'A', 'one', '2026-08-04', ['Hidden'], ['Hidden'], 0, 0],
            ['deleted', 'A', 'one', '2026-08-05', ['Deleted'], ['Deleted'], 1, 1],
            ['old', 'A', 'one', '2026-07-01', ['Old'], ['Old'], 1, 0],
            ['empty', 'A', 'one', '2026-08-06', [], [], 1, 0],
            ['missing', 'A', 'one', '2026-08-07', null, null, 1, 0],
        ] as $row) {
            $row[4] = $row[4] === null ? null : json_encode($row[4]);
            $row[5] = $row[5] === null ? null : json_encode($row[5]);
            $insert->execute($row);
            $this->pdo->prepare('INSERT INTO visibility (id, episode_id) VALUES (?, ?)')->execute([$row[0], $row[0]]);
        }
        $this->pdo->exec("INSERT INTO visibility (id, episode_id) VALUES ('duplicate-a', 'a')");
    }

    private function applyWhere(SelectBuilder $query, Item $where): void
    {
        if ($where->getType() === 'and') {
            foreach ($where->getItemList() as $item) $this->applyWhere($query, $item);
            return;
        }
        $operator = match ($where->getType()) { 'equals' => '', 'greaterThanOrEquals' => '>=' };
        $query->where([$where->getAttribute() . $operator => $where->getValue()]);
    }

    private function cohort(): Item
    {
        return Item::fromRaw(['type' => 'greaterThanOrEquals', 'attribute' => 'startedAt', 'value' => '2026-08-01']);
    }

    public function testDistinctParticipationByAccountWithAclAndCommaInName(): void
    {
        $result = $this->agents->run($this->cohort(), $this->user);
        $data = $result->getReportData();
        self::assertSame(2, $data->A->{hash('sha256', 'Alice')}->{'COUNT:id'});
        self::assertSame(1, $data->B->{hash('sha256', 'Alice')}->{'COUNT:id'});
        self::assertSame(1, $data->A->{hash('sha256', 'Bob, Jr.')}->{'COUNT:id'});
        self::assertSame(4, $result->getSums()->{'COUNT:id'});
        self::assertCount(2, $result->getGroupValueMap()['lifecycleAssignees']);
        self::assertSame('restrictedAccount', $result->getGroupValueMap()['chatwootAccount']['B']);
    }

    public function testTeamHandoffsCountEachEpisodeOncePerTeam(): void
    {
        $result = $this->teams->run($this->cohort(), $this->user);
        self::assertSame(2, $result->getReportData()->A->{hash('sha256', 'Sales')}->{'COUNT:id'});
        self::assertSame(1, $result->getReportData()->A->{hash('sha256', 'Support')}->{'COUNT:id'});
        self::assertSame(4, $result->getSums()->{'COUNT:id'});
    }

    public function testDrillDownFiltersMembershipBeforePaginationAndKeepsTotal(): void
    {
        $params = SearchParams::create()->withWhere($this->cohort())->withOffset(1)->withMaxSize(1)->withSelect(['id']);
        $result = $this->agents->runSubReport($params, new SubReportParams(1, hash('sha256', 'Alice'), true, 'A'), $this->user);
        self::assertSame(2, $result->getTotal());
        self::assertSame(['a'], array_map(fn ($entity) => $entity->getId(), iterator_to_array($result->getCollection())));
    }

    public function testAccountTotalDrillDownIsUniqueEpisodesRatherThanRepeatedParticipation(): void
    {
        $params = SearchParams::create()->withWhere($this->cohort());
        $result = $this->agents->runSubReport($params, new SubReportParams(0, 'A'), $this->user);
        self::assertSame(2, $result->getTotal());
        self::assertCount(2, $result->getCollection());
    }

    public function testCrossAccountColumnAndAdditionalInboxFilter(): void
    {
        $params = SearchParams::create()->withWhere($this->cohort())
            ->withWhereAdded(Item::fromRaw(['type' => 'equals', 'attribute' => 'inboxId', 'value' => 'one']));
        $result = $this->agents->runSubReport($params, new SubReportParams(1, hash('sha256', 'Alice')), $this->user);
        self::assertSame(2, $result->getTotal());
        $ids = array_map(fn ($entity) => $entity->getId(), iterator_to_array($result->getCollection()));
        sort($ids);
        self::assertSame(['a', 'c'], $ids);
    }

    public function testForgedParticipantReturnsNoEpisodes(): void
    {
        $result = $this->agents->runSubReport(SearchParams::create(), new SubReportParams(1, 'unknown'), $this->user);
        self::assertSame(0, $result->getTotal());
        self::assertCount(0, $result->getCollection());
    }

    public function testEmptyCohortProducesAnEmptyGrid(): void
    {
        $where = Item::fromRaw(['type' => 'greaterThanOrEquals', 'attribute' => 'startedAt', 'value' => '2030-01-01']);
        $result = $this->agents->run($where, $this->user);
        self::assertSame(0, $result->getSums()->{'COUNT:id'});
        self::assertEquals((object) [], $result->getReportData());
    }

    public function testInvalidGroupIndexIsRejected(): void
    {
        $this->expectException(BadRequest::class);
        $this->agents->runSubReport(SearchParams::create(), new SubReportParams(2, 'A'), $this->user);
    }
}
