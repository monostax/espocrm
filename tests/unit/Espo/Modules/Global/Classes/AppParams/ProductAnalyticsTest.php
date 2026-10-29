<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Classes\AppParams;

use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\Modules\Global\Classes\AppParams\ProductAnalytics;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class ProductAnalyticsTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function param(array $config, ?int $chatwootUserId, string $type = 'regular'): ProductAnalytics
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('crm1');
        $user->method('isRegular')->willReturn($type === 'regular');
        $user->method('isAdmin')->willReturn($type === 'admin');

        $settings = $this->createMock(Config::class);
        $settings->method('get')->willReturnCallback(fn (string $key) => $config[$key] ?? null);

        $chatwootUser = null;

        if ($chatwootUserId !== null) {
            $chatwootUser = $this->createMock(Entity::class);
            $chatwootUser->method('get')->willReturnMap([['chatwootUserId', $chatwootUserId]]);
        }

        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('where')->with(['assignedUserId' => 'crm1'])->willReturnSelf();
        $builder->method('order')->willReturnSelf();
        $builder->method('findOne')->willReturn($chatwootUser);
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('select')->willReturn($builder);
        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')->with('ChatwootUser')->willReturn($repository);

        return new ProductAnalytics($user, $settings, $entityManager);
    }

    public function testOffWithoutToken(): void
    {
        $this->assertNull($this->param([], 5)->get());
        $this->assertNull($this->param(['posthogProjectToken' => ''], 5)->get());
    }

    public function testOffForPortalAndApiUsers(): void
    {
        $this->assertNull($this->param(['posthogProjectToken' => 'phc_x'], 5, 'portal')->get());
    }

    public function testIdentifiesAsTheChatwootUser(): void
    {
        $this->assertSame(
            ['token' => 'phc_x', 'host' => 'https://eu.i.posthog.com', 'distinctId' => 'user-5'],
            $this->param(['posthogProjectToken' => 'phc_x', 'posthogHost' => 'https://eu.i.posthog.com'], 5)->get(),
        );
    }

    public function testUsersWithoutChatwootUserReportAsCrmUsers(): void
    {
        $this->assertSame(
            ['token' => 'phc_x', 'host' => 'https://us.i.posthog.com', 'distinctId' => 'crm-user-crm1'],
            $this->param(['posthogProjectToken' => 'phc_x'], null, 'admin')->get(),
        );
    }
}
