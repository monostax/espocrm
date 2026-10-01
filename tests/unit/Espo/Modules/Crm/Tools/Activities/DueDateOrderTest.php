<?php

namespace tests\unit\Espo\Modules\Crm\Tools\Activities;

use DateTimeImmutable;
use Espo\Modules\Crm\Tools\Activities\Service;
use Espo\ORM\EntityFactory;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\UnionBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class DueDateOrderTest extends TestCase
{
    public function testDueDatesAreOrderedBeforePagination(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->sqliteCreateFunction('IF', fn ($condition, $yes, $no) => $condition ? $yes : $no, 3);
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn([]);
        $composer = new MysqlQueryComposer(
            $pdo,
            $this->createMock(EntityFactory::class),
            new Metadata($provider),
        );
        $union = new UnionBuilder();
        // Local time is still Oct 1, although UTC has crossed midnight.
        $rows = [
            ['future', '2026-10-13 10:00:00', null],
            ['undated', null, null],
            ['old-overdue', null, '2026-09-25'],
            ['today-all-day', null, '2026-10-01'],
            ['recent-overdue', '2026-10-02 00:30:00', null],
            ['soon', '2026-10-02 02:00:00', null],
            ['yesterday', null, '2026-09-30'],
        ];
        foreach ($rows as [$id, $dateEnd, $dateEndDate]) {
            $union->query((new SelectBuilder())->select([
                [Expr::value($id), 'id'],
                [Expr::value('Task'), '_scope'],
                [Expr::value($dateEnd), 'dateEnd'],
                [Expr::value($dateEndDate), 'dateEndDate'],
            ])->build());
        }
        $builder = (new SelectBuilder())->fromQuery($union->build(), 'activity')->select('activity.id', 'id');
        $service = (new ReflectionClass(Service::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod(Service::class, 'applyDueDateOrder'))->invoke(
            $service,
            $builder,
            new DateTimeImmutable('2026-10-01T22:00:00-03:00'),
        );
        $ids = function ($query) use ($pdo, $composer) {
            $sql = $composer->compose($query);
            // SQLite accepts the same expressions but not MySQL's parentheses around UNION members.
            $sql = str_replace(['((SELECT', ') UNION (SELECT', ')) AS `activity`'],
                ['(SELECT', ' UNION SELECT', ') AS `activity`'], $sql);
            try {
                return $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
            } catch (\PDOException $e) {
                $this->fail($e->getMessage() . "\n" . $sql);
            }
        };
        $this->assertSame([
            'recent-overdue', 'yesterday', 'old-overdue', 'today-all-day', 'soon', 'future', 'undated',
        ], $ids($builder->build()));
        $builder->limit(0, 2);
        $this->assertSame(['recent-overdue', 'yesterday'], $ids($builder->build()));
        $builder->limit(2, 2);
        $this->assertSame(['old-overdue', 'today-all-day'], $ids($builder->build()));
    }
}
