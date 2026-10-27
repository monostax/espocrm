<?php

declare(strict_types=1);

namespace tests\unit\Espo\Core\Authentication\AuthToken;

use Espo\Core\Authentication\AuthToken\EspoManager;
use Espo\Core\Field\DateTime;
use Espo\Entities\AuthToken;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use PHPUnit\Framework\TestCase;

class EspoManagerTest extends TestCase
{
    private function token(?string $lastAccess): AuthToken
    {
        $attributes = ['id', 'token', 'secret', 'hash', 'userId', 'portalId', 'lastAccess'];
        $token = new AuthToken('AuthToken', ['attributes' => array_fill_keys($attributes, ['type' => 'varchar'])]);
        $token->set(['id' => 'id', 'token' => 'token', 'userId' => 'user', 'lastAccess' => $lastAccess]);
        $token->setAsNotNew();
        $token->setAsFetched();
        return $token;
    }

    private function manager(int $saves): EspoManager
    {
        $repository = $this->createMock(RDBRepository::class);
        $repository->expects($this->exactly($saves))->method('save');
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepositoryByClass')->willReturn($repository);
        return new EspoManager($em);
    }

    public function testRenewWritesLastAccessAtMostOncePerMinute(): void
    {
        $recent = DateTime::createNow()->addSeconds(-30)->toString();
        $token = $this->token($recent);
        $this->manager(0)->renew($token);
        $this->assertSame($recent, $token->get('lastAccess'));

        foreach ([DateTime::createNow()->addSeconds(-61)->toString(), null] as $lastAccess) {
            $token = $this->token($lastAccess);
            $this->manager(1)->renew($token);
            $this->assertNotSame($lastAccess, $token->get('lastAccess'));
        }
    }
}
