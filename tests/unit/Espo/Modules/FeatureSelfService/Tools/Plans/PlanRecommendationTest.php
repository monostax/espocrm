<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSelfService\Tools\Plans;

use Espo\Modules\FeatureSelfService\Tools\Plans\PlanRecommendation;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlanRecommendationTest extends TestCase
{
    public function testCustomerSeatsExcludeInternalStaffAndKeepTheCustomerAdministrator(): void
    {
        $result = $this->recommend([
            'admin@app.monostax.ai',
            'staff@monostax.com',
            'support@monostax.com.br',
            'staff@monostax.ai',
            'integration@monostax-ext.com',
            'agent@customer.monostax-ext.com',
            'owner@customer.test',
            'agent@gmail.com',
            'agent@partner.test',
        ]);

        self::assertSame(3, $result['attendants']);
        self::assertSame(3, $result['seats']);
        self::assertSame('0', $result['credits']);
    }

    #[DataProvider('emailCases')]
    public function testEmailDomainBoundaries(?string $email, int $billable): void
    {
        $result = $this->recommend([$email]);

        self::assertSame($billable, $result['attendants']);
        self::assertSame(1, $result['seats'], 'Checkout retains its one-seat minimum.');
    }

    public static function emailCases(): array
    {
        return [
            'com' => ['staff@monostax.com', 0],
            'com br' => ['staff@monostax.com.br', 0],
            'ai' => ['staff@monostax.ai', 0],
            'mixed case and whitespace' => [' Staff@MONOSTAX.COM.BR ', 0],
            'admin subdomain' => ['admin@app.monostax.ai', 0],
            'nested subdomain' => ['staff@support.br.monostax.com', 0],
            'com br subdomain' => ['staff@app.monostax.com.br', 0],
            'machine identity' => ['integration@monostax-ext.com', 0],
            'tenant machine identity' => ['agent@finapelespa.monostax-ext.com', 0],
            'machine identity mixed case' => [' Agent@FINAPELESPA.MONOSTAX-EXT.COM ', 0],
            'machine identity suffix lookalike' => ['customer@notmonostax-ext.com', 1],
            'machine identity prefix lookalike' => ['customer@monostax-ext.com.example.test', 1],
            'suffix lookalike' => ['customer@notmonostax.com', 1],
            'prefix lookalike' => ['customer@monostax.com.example.test', 1],
            'ai lookalike' => ['customer@notmonostax.ai', 1],
            'name is not domain' => ['monostax.com@gmail.com', 1],
            'missing email' => [null, 1],
            'empty email' => ['', 1],
        ];
    }

    private function recommend(array $emails): array
    {
        $tenant = $this->createStub(Entity::class);
        $tenant->method('get')->willReturn(null);
        $account = $this->createStub(Entity::class);
        $account->method('getId')->willReturn('customer-account');
        $account->method('get')->willReturnCallback(fn ($field) => $field === 'conciergeUserId' ? 'concierge' : null);

        $memberships = array_map(function (?string $email): Entity {
            $membership = $this->createStub(Entity::class);
            $membership->method('get')->willReturnCallback(fn ($field) => $field === 'email' ? $email : null);

            return $membership;
        }, $emails);

        $select = $this->createStub(RDBSelectBuilder::class);
        $select->method('find')->willReturn(new EntityCollection($memberships));
        $repo = $this->createMock(RDBRepository::class);
        $repo->expects(self::once())->method('where')->with([
            'chatwootAccountId' => 'customer-account',
            'OR' => [['isAI' => false], ['isAI' => null]],
            'chatwootUserId!=' => 'concierge',
        ])->willReturn($select);
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::once())->method('getRDBRepository')->with('ChatwootAccountUserMembership')->willReturn($repo);

        return (new PlanRecommendation($em))->recommend($tenant, $account,
            ['includedCredits' => 250], [['key' => '0', 'credits' => 0]]);
    }
}
