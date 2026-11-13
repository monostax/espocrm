<?php
declare(strict_types=1);
namespace tests\unit\Espo\Modules\FeatureAiSession;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureAiSession\Services\Access;
use Espo\Modules\FeatureAiSession\Services\Primary;
use Espo\Modules\FeatureAiSession\Services\Submissions;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PDOException;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

require_once __DIR__ . '/../Chatwoot/Support/EntityDouble.php';

class PrimaryTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $created = [];

    public function testTheExistingMainChatIsReused(): void
    {
        $main = new EntityDouble(['id' => 'main', 'primarySlot' => 'main']);
        self::assertSame($main, $this->primary([$main])->ensure($this->account()));
        self::assertSame([], $this->created);
    }

    public function testItIsMadeOnceWithTheAgentOfThePersonsLatestChat(): void
    {
        $made = new EntityDouble(['id' => 'made']);
        $primary = $this->primary([null], latest: new EntityDouble(['aiAgentMembershipId' => 'latestAgent']), allowed: ['latestAgent', 'first'],
            create: fn () => $made);
        self::assertSame($made, $primary->ensure($this->account()));
        self::assertSame([['name' => 'Principal', 'chatwootAccountId' => 'account', 'aiAgentMembershipId' => 'latestAgent', 'primarySlot' => 'main']],
            $this->created);
    }

    public function testAnAgentThePersonMayNoLongerUseIsSkipped(): void
    {
        $this->primary([null], latest: new EntityDouble(['aiAgentMembershipId' => 'revoked']), allowed: ['second'],
            create: fn () => new EntityDouble(['id' => 'made']))->ensure($this->account());
        self::assertSame('second', $this->created[0]['aiAgentMembershipId']);
    }

    public function testARacingRequestsChatIsKept(): void
    {
        $theirs = new EntityDouble(['id' => 'theirs']);
        $primary = $this->primary([null, $theirs], allowed: ['first'], create: function () {
            throw new PDOException('Duplicate entry', 23000);
        });
        self::assertSame($theirs, $primary->ensure($this->account()));
    }

    public function testWithoutAnAgentThereIsNoMainChat(): void
    {
        self::assertNull($this->primary([null], allowed: [])->ensure($this->account()));
        self::assertNull($this->primary([null], allowed: ['first'], canCreate: false)->ensure($this->account()));
        self::assertSame([], $this->created);
    }

    public function testOnlyTheMainSlotIsTheMainChat(): void
    {
        self::assertTrue(Primary::is(new EntityDouble(['primarySlot' => 'main'])));
        self::assertFalse(Primary::is(new EntityDouble(['primarySlot' => null])));
    }

    private function account(): EntityDouble
    {
        return new EntityDouble(['id' => 'account', 'tenantId' => 'tenant']);
    }

    /**
     * @param list<?EntityDouble> $mainLookups what each lookup of the main chat finds, in order
     * @param list<string> $allowed agents the person may use; the workspace's AIs are "first" then "second"
     */
    private function primary(array $mainLookups, ?EntityDouble $latest = null, array $allowed = [], ?callable $create = null,
        bool $canCreate = true): Primary
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('human');
        $em = $this->createMock(EntityManager::class);
        $em->method('getNewEntity')->willReturnCallback(fn () => new EntityDouble());
        $em->method('createEntity')->willReturnCallback(function (string $type, array $values) use ($create) {
            $this->created[] = $values;
            return $create ? $create() : new EntityDouble($values);
        });
        $em->method('getRDBRepository')->willReturnCallback(function (string $type) use (&$mainLookups, $latest) {
            $repository = $this->createMock(RDBRepository::class);
            $repository->method('where')->willReturnCallback(function (array $where) use ($type, &$mainLookups, $latest) {
                $builder = $this->createMock(RDBSelectBuilder::class);
                $builder->method('order')->willReturnSelf();
                if ($type === 'ChatwootAccountUserMembership') {
                    $builder->method('find')->willReturn(new EntityCollection([
                        new EntityDouble(['id' => 'first']), new EntityDouble(['id' => 'second']),
                    ]));
                } elseif (isset($where['primarySlot'])) {
                    $builder->method('findOne')->willReturnCallback(function () use (&$mainLookups) {
                        return array_shift($mainLookups);
                    });
                } else {
                    $builder->method('findOne')->willReturn($latest);
                }
                return $builder;
            });
            return $repository;
        });
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn($canCreate);
        $access = $this->createMock(Access::class);
        $access->method('agent')->willReturnCallback(function ($owner, $session, string $id) use ($allowed) {
            if (!in_array($id, $allowed, true)) throw new Forbidden();
            return new EntityDouble(['id' => $id]);
        });
        return new Primary($em, $user, $acl, $access, $this->createMock(Submissions::class));
    }
}
