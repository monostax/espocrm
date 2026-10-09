<?php
declare(strict_types=1);
namespace tests\unit\Espo\Modules\FeatureAiSession;

use Espo\Core\AclManager;
use Espo\Core\Job\Job\Data;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureAiSession\Services\Access;
use Espo\Modules\FeatureAiSession\Services\Broadcast;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

require_once __DIR__ . '/../Chatwoot/Support/EntityDouble.php';

class BroadcastTest extends TestCase
{
    public function testPrivateInvalidationResolvesLiveOwnerAndDoesNotBroadcastAfterRevocation(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn('owner');
        $records = [
            'AiSession/session' => new EntityDouble(['id' => 'session', 'assignedUserId' => 'owner', 'chatwootAccountId' => 'crm-account']),
            'User/owner' => $owner,
            'ChatwootAccount/crm-account' => new EntityDouble(['id' => 'crm-account', 'platformId' => 'platform', 'chatwootAccountId' => 9, 'apiKey' => 'test-key']),
            'ChatwootPlatform/platform' => new EntityDouble(['backendUrl' => 'https://chat.example.test']),
            'ChatwootUser/identity' => new EntityDouble(['chatwootUserId' => 42]),
        ];
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $records["$type/$id"] ?? null);
        $repo = $this->createMock(RDBRepository::class);
        $select = $this->createMock(RDBSelectBuilder::class);
        $repo->method('join')->willReturn($select);
        $select->method('where')->with([
            'chatwootAccountId' => 'crm-account', 'chatwootUser.assignedUserId' => 'owner',
            'chatwootUser.deleted' => false, 'chatwootUser.platformId' => 'platform',
        ])->willReturnSelf();
        $select->method('find')->willReturn(new EntityCollection([new EntityDouble(['chatwootUserId' => 'identity'])]));
        $em->method('getRDBRepository')->with('ChatwootAccountUserMembership')->willReturn($repo);
        $access = $this->createMock(Access::class);
        $access->method('owner')->willReturn(true);
        $allowed = true;
        $acl = $this->createMock(AclManager::class);
        $acl->method('checkEntityRead')->willReturnCallback(function () use (&$allowed) { return $allowed; });
        $acl->method('checkEntityStream')->willReturn(true);
        $client = $this->createMock(ChatwootApiClient::class);
        $client->expects(self::once())->method('notifyActivityUpdate')->with('https://chat.example.test', 'test-key', 9,
            ['entityType' => 'AiSession', 'ownerUserId' => 42]);
        $job = new Broadcast($em, $access, $client, $acl);
        $job->run(Data::create(['id' => 'session', 'ownerUserId' => 999]));
        $allowed = false;
        $job->run(Data::create(['id' => 'session']));
    }
}
