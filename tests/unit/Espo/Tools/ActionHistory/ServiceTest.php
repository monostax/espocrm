<?php

declare(strict_types=1);

namespace tests\unit\Espo\Tools\ActionHistory;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\BaseEntity;
use Espo\ORM\CollectionFactory;
use Espo\ORM\Defs\Defs;
use Espo\ORM\Defs\EntityDefs;
use Espo\ORM\EntityCollection;
use Espo\ORM\Executor\QueryExecutor;
use Espo\ORM\QueryBuilder;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\Tools\ActionHistory\Service;
use PHPUnit\Framework\TestCase;

class ServiceTest extends TestCase
{
    private function row(string $type, string $id, int $number): BaseEntity
    {
        $attributes = ['id', 'targetType', 'targetId', 'targetName', 'number', 'createdAt'];
        $entity = new BaseEntity('ActionHistoryRecord', ['attributes' => array_fill_keys($attributes, ['type' => 'varchar'])]);
        $entity->set(['targetType' => $type, 'targetId' => $id, 'number' => $number]);
        return $entity;
    }

    public function testLastViewedLoadsTargetNamesWithOneQueryPerType(): void
    {
        $rows = [
            $this->row('Account', 'a1', 9), $this->row('Contact', 'c1', 8),
            $this->row('Account', 'a2', 7), $this->row('Removed', 'r1', 6),
        ];
        $history = $this->createMock(RDBSelectBuilder::class);
        foreach (['where', 'select', 'order', 'limit'] as $method) $history->method($method)->willReturnSelf();
        $history->method('find')->willReturn(new EntityCollection($rows));
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturn($history);
        $names = [
            'Account' => [['id' => 'a1', 'name' => 'Acme'], ['id' => 'a2', 'name' => '']],
            'Contact' => [['id' => 'c1', 'name' => 'Deleted contact']],
        ];
        $queries = [];
        $executor = $this->createMock(QueryExecutor::class);
        $executor->expects($this->exactly(2))->method('execute')->willReturnCallback(function ($query) use (&$queries, $names) {
            $queries[] = $query->getRaw();
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('fetchAll')->willReturn($names[$query->getFrom()]);
            return $statement;
        });
        $entityDefs = $this->createMock(EntityDefs::class);
        $entityDefs->method('hasAttribute')->with('name')->willReturn(true);
        $defs = $this->createMock(Defs::class);
        $defs->method('getEntity')->willReturn($entityDefs);
        $collections = $this->createMock(CollectionFactory::class);
        $collections->method('create')->willReturnCallback(fn ($type, $list) => new EntityCollection($list, $type));
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepositoryByClass')->willReturn($repository);
        $em->method('getCollectionFactory')->willReturn($collections);
        $em->method('hasRepository')->willReturnCallback(fn ($type) => $type !== 'Removed');
        $em->method('getDefs')->willReturn($defs);
        $em->method('getQueryBuilder')->willReturn(new QueryBuilder());
        $em->method('getQueryExecutor')->willReturn($executor);
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->with('scopes')->willReturn([
            'Account' => ['object' => true], 'Contact' => ['object' => true], 'Removed' => ['object' => true],
        ]);

        $result = (new Service($metadata, $em, $this->createMock(User::class)))->getLastViewed(10, 0);

        $this->assertSame(
            [['a1', 'Acme'], ['c1', 'Deleted contact'], ['a2', null], ['r1', null]],
            array_map(fn ($entity) => [$entity->get('targetId'), $entity->get('targetName')],
                iterator_to_array($result->getCollection())));
        $this->assertSame(['Account', 'Contact'], array_column($queries, 'from'));
        $this->assertSame([true, true], array_column($queries, 'withDeleted'));
        $this->assertSame(['a1', 'a2'], $queries[0]['whereClause']['id']);
    }
}
