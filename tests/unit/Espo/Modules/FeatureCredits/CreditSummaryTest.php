<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\UsageSummary;
use Espo\Modules\FeatureCredits\Controllers\CreditSummary;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CreditSummaryTest extends TestCase
{
    public function testAuthenticatedNonEntityRoute(): void
    {
        $root = 'custom/Espo/Modules/FeatureCredits/Resources/';
        $routes = json_decode(file_get_contents($root . 'routes.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['route' => '/CreditSummary', 'method' => 'get',
            'params' => ['controller' => 'CreditSummary', 'action' => 'read']]],
            array_values(array_filter($routes, static fn ($r) => $r['route'] === '/CreditSummary')));
        $scope = json_decode(file_get_contents($root . 'metadata/scopes/CreditSummary.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($scope['entity']);
        $this->assertSame('FeatureCredits', $scope['module']);
    }

    public static function invalidQueries(): iterable
    {
        $valid = ['tenantId' => 'tenant', 'from' => '2026-11-01 00:00:00', 'to' => '2026-11-08 00:00:00'];
        yield [[]];
        yield [['tenantId' => '', 'from' => $valid['from'], 'to' => $valid['to']]];
        yield [['tenantId' => 'tenant', 'from' => $valid['from']]];
        yield [['tenantId' => 'tenant', 'to' => $valid['to']]];
        yield [['tenantId' => 'tenant', 'from' => '2026-02-30 00:00:00', 'to' => '2026-03-01 00:00:00']];
        yield [['tenantId' => 'tenant', 'from' => '2026-11-08 00:00:00', 'to' => '2026-11-08 00:00:00']];
        yield [['tenantId' => 'tenant', 'from' => '2026-11-09 00:00:00', 'to' => '2026-11-08 00:00:00']];
        yield [['tenantId' => 'tenant', 'from' => '2025-01-01 00:00:00', 'to' => '2026-01-03 00:00:00']];
        foreach (['900', '-900', 'abc', '01', '1.5', []] as $tz) {
            yield [$valid + ['tzOffsetMinutes' => $tz]];
        }
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueryNeverReadsAccounting(array $query): void
    {
        $access = $this->createMock(Access::class);
        $access->expects($this->never())->method('assertTenant');
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->never())->method('getPDO');
        $this->expectException(BadRequest::class);
        $this->invoke($query, $access, $manager);
    }

    public static function deniedUsers(): array
    {
        return [['member'], ['portal'], ['api'], ['foreign-admin'], ['missing-tenant']];
    }

    #[DataProvider('deniedUsers')]
    public function testAuthorizationBeforeAccounting(string $kind): void
    {
        $user = $this->createMock(User::class);
        $user->method('isPortal')->willReturn($kind === 'portal');
        $user->method('isApi')->willReturn($kind === 'api');
        $user->method('isAdmin')->willReturn(in_array($kind, ['portal', 'api', 'missing-tenant'], true));
        $user->method('isEffectiveAdmin')->willReturn(in_array($kind, ['portal', 'api', 'missing-tenant'], true));
        $user->method('getLinkMultipleIdList')->willReturn($kind === 'foreign-admin' ? [md5('tenant-admin')] : []);
        $memberships = $this->createMock(UserTenantResolver::class);
        $memberships->method('resolveTenantIds')->willReturn(['other']);
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->never())->method('getPDO');
        $access = new Access($user, $manager, $this->createMock(TenantResolver::class), $memberships);
        $this->expectException(Forbidden::class);
        $this->invoke([
            'tenantId' => 'tenant',
            'from' => '2026-11-22 03:00:00',
            'to' => '2026-11-23 03:00:00',
            'tzOffsetMinutes' => '-180',
        ], $access, $manager);
    }

    public function testAggregatesCategoriesEfficiencyDailyAndUsers(): void
    {
        $rows = [
            // Contact c1 enriched by u1 (6 cr)
            [
                'id' => 'u_enr_1', 'operation_type' => 'enrichment', 'source_type' => null, 'source_id' => null,
                'admitted_at' => '2026-11-22 18:00:00', 'settled_credits' => '6.0000', 'evidence' => null, 'actor_id' => null,
                'verification_kind' => null, 'verification_state' => null, 'verification_outcome' => null,
                'verification_contact_id' => null, 'verification_subject_hash' => null, 'verification_actor_id' => null,
                'verification_automated' => 0,
                'enrichment_target_type' => 'Contact', 'enrichment_target_id' => 'c1', 'enrichment_actor_id' => 'u1',
            ],
            // c1 whatsapp registered (2 cr, automated by u2)
            [
                'id' => 'u_wa_1', 'operation_type' => 'verification', 'source_type' => 'Contact', 'source_id' => 'c1',
                'admitted_at' => '2026-11-22 18:01:00', 'settled_credits' => '2.0000', 'evidence' => null, 'actor_id' => null,
                'verification_kind' => 'whatsapp_registration', 'verification_state' => 'completed', 'verification_outcome' => 'registered',
                'verification_contact_id' => 'c1', 'verification_subject_hash' => 'p1', 'verification_actor_id' => 'u2',
                'verification_automated' => 1,
                'enrichment_target_type' => null, 'enrichment_target_id' => null, 'enrichment_actor_id' => null,
            ],
            // c1 whatsapp not_registered (2 cr, automated by u2)
            [
                'id' => 'u_wa_2', 'operation_type' => 'verification', 'source_type' => 'Contact', 'source_id' => 'c1',
                'admitted_at' => '2026-11-22 18:01:10', 'settled_credits' => '2.0000', 'evidence' => null, 'actor_id' => null,
                'verification_kind' => 'whatsapp_registration', 'verification_state' => 'completed', 'verification_outcome' => 'not_registered',
                'verification_contact_id' => 'c1', 'verification_subject_hash' => 'p2', 'verification_actor_id' => 'u2',
                'verification_automated' => 1,
                'enrichment_target_type' => null, 'enrichment_target_id' => null, 'enrichment_actor_id' => null,
            ],
            // c1 email deliverable (2 cr, automated by u2)
            [
                'id' => 'u_em_1', 'operation_type' => 'verification', 'source_type' => 'Contact', 'source_id' => 'c1',
                'admitted_at' => '2026-11-22 18:01:20', 'settled_credits' => '2.0000', 'evidence' => null, 'actor_id' => null,
                'verification_kind' => 'email_deliverability', 'verification_state' => 'completed', 'verification_outcome' => 'deliverable',
                'verification_contact_id' => 'c1', 'verification_subject_hash' => 'e1', 'verification_actor_id' => 'u2',
                'verification_automated' => 1,
                'enrichment_target_type' => null, 'enrichment_target_id' => null, 'enrichment_actor_id' => null,
            ],
            // c1 phone_identity verified (8 cr, automated by u2)
            [
                'id' => 'u_ph_1', 'operation_type' => 'verification', 'source_type' => 'Contact', 'source_id' => 'c1',
                'admitted_at' => '2026-11-22 18:02:00', 'settled_credits' => '8.0000', 'evidence' => null, 'actor_id' => null,
                'verification_kind' => 'phone_identity', 'verification_state' => 'completed', 'verification_outcome' => 'verified',
                'verification_contact_id' => 'c1', 'verification_subject_hash' => 'p1', 'verification_actor_id' => 'u2',
                'verification_automated' => 1,
                'enrichment_target_type' => null, 'enrichment_target_id' => null, 'enrichment_actor_id' => null,
            ],
            // c1 phone_identity waived (0 cr, automated by u2)
            [
                'id' => 'u_ph_2', 'operation_type' => 'verification', 'source_type' => 'Contact', 'source_id' => 'c1',
                'admitted_at' => '2026-11-22 18:02:30', 'settled_credits' => '0.0000', 'evidence' => null, 'actor_id' => null,
                'verification_kind' => 'phone_identity', 'verification_state' => 'waived', 'verification_outcome' => 'failed',
                'verification_contact_id' => 'c1', 'verification_subject_hash' => 'p2', 'verification_actor_id' => 'u2',
                'verification_automated' => 1,
                'enrichment_target_type' => null, 'enrichment_target_id' => null, 'enrichment_actor_id' => null,
            ],
            // AI conversation reply (system, 1.5000 cr) at 01:15 UTC next day = 22:15 BRT same local day!
            [
                'id' => 'u_ai_1', 'operation_type' => 'ai', 'source_type' => 'ChatwootConversation', 'source_id' => 'conv1',
                'admitted_at' => '2026-11-23 01:15:00', 'settled_credits' => '1.5000', 'evidence' => null, 'actor_id' => null,
                'verification_kind' => null, 'verification_state' => null, 'verification_outcome' => null,
                'verification_contact_id' => null, 'verification_subject_hash' => null, 'verification_actor_id' => null,
                'verification_automated' => 0,
                'enrichment_target_type' => null, 'enrichment_target_id' => null, 'enrichment_actor_id' => null,
            ],
            // AI mural note by u1 (3.5000 cr)
            [
                'id' => 'u_ai_2', 'operation_type' => 'ai', 'source_type' => 'Note', 'source_id' => 'note1',
                'admitted_at' => '2026-11-22 19:00:00', 'settled_credits' => '3.5000', 'evidence' => null, 'actor_id' => null,
                'verification_kind' => null, 'verification_state' => null, 'verification_outcome' => null,
                'verification_contact_id' => null, 'verification_subject_hash' => null, 'verification_actor_id' => null,
                'verification_automated' => 0,
                'enrichment_target_type' => null, 'enrichment_target_id' => null, 'enrichment_actor_id' => null,
            ],
        ];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('getAttribute')->willReturnMap([
            [PDO::ATTR_DRIVER_NAME, 'mysql'],
            [PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION],
        ]);
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('commit');

        $tenantStmt = $this->createMock(PDOStatement::class);
        $tenantStmt->method('fetchColumn')->willReturn('tenant');

        $rowsStmt = $this->createMock(PDOStatement::class);
        $rowsStmt->method('fetchAll')->willReturn($rows);

        $noteStmt = $this->createMock(PDOStatement::class);
        $noteStmt->method('fetchAll')->willReturn([['id' => 'note1', 'actor_id' => 'u1']]);

        $userStmt = $this->createMock(PDOStatement::class);
        $userStmt->method('fetchAll')->willReturn([
            ['id' => 'u1', 'first_name' => 'Manuella', 'last_name' => 'Moura', 'user_name' => 'manuella@monostax.com'],
            ['id' => 'u2', 'first_name' => 'Antonio', 'last_name' => 'Moura', 'user_name' => 'am@monostax.com'],
        ]);

        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use ($tenantStmt, $rowsStmt, $noteStmt, $userStmt) {
            if (str_contains($sql, 'FROM tenant')) {
                return $tenantStmt;
            }
            if (str_contains($sql, 'FROM credit_usage')) {
                return $rowsStmt;
            }
            if (str_contains($sql, 'FROM note')) {
                return $noteStmt;
            }
            if (str_contains($sql, 'FROM `user`')) {
                return $userStmt;
            }
            throw new \RuntimeException('Unexpected SQL: ' . $sql);
        });

        $manager = $this->createMock(EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);

        $access = $this->createMock(Access::class);
        $access->expects($this->once())->method('assertTenant')->with('tenant');

        $result = (array) $this->invoke([
            'tenantId' => 'tenant',
            'from' => '2026-11-22 03:00:00',
            'to' => '2026-11-23 03:00:00',
            'tzOffsetMinutes' => '-180',
        ], $access, $manager);

        $this->assertSame('25.0000', $result['totals']['credits']);
        $this->assertSame(8, $result['totals']['operations']);
        $this->assertSame(7, $result['totals']['chargedOperations']);
        $this->assertSame(1, $result['totals']['waivedOperations']);

        // Single local day (2026-11-22 BRT) contains all 8 ops including 01:15 UTC on Nov 23.
        $this->assertCount(1, $result['daily']);
        $this->assertSame('2026-11-22', $result['daily'][0]['date']);
        $this->assertSame('25.0000', $result['daily'][0]['credits']);

        // Unit economics:
        // WhatsApp: 4.0000 / 1 valid = 4.0000 (hit rate 1/2 = 0.5)
        // Email: 2.0000 / 1 valid = 2.0000 (hit rate 1/1 = 1.0)
        // Phone: 8.0000 / 1 valid = 8.0000 (waived op excluded from checked)
        // Contact pipeline: 6 enrichment + 14 verification = 20.0000 / 1 valid contact = 20.0000
        $eff = $result['efficiency'];
        $this->assertSame(1, $eff['validWhatsApps']);
        $this->assertSame(2, $eff['checkedWhatsApps']);
        $this->assertSame('4.0000', $eff['avgCostPerValidWhatsApp']);
        $this->assertSame(0.5, $eff['whatsappHitRate']);
        $this->assertSame(1, $eff['validEmails']);
        $this->assertSame('2.0000', $eff['avgCostPerValidEmail']);
        $this->assertSame(1, $eff['validPhones']);
        $this->assertSame('8.0000', $eff['avgCostPerValidPhone']);
        $this->assertSame(1, $eff['validContacts']);
        $this->assertSame(1, $eff['fullyVerifiedContacts']);
        $this->assertSame('20.0000', $eff['contactPipelineCredits']);
        $this->assertSame('20.0000', $eff['avgCostPerValidContact']);
        $this->assertSame('1.5000', $eff['avgCostPerAiConversation']);

        // Users sorted by totalCredits DESC: u2 (14.0000, all automated), u1 (9.5000 = 6 enr + 3.5 mural), system (1.5000)
        $this->assertCount(3, $result['byUser']);
        $this->assertSame('Antonio Moura', $result['byUser'][0]['name']);
        $this->assertSame('14.0000', $result['byUser'][0]['totalCredits']);
        $this->assertSame('14.0000', $result['byUser'][0]['automatedCredits']);
        $this->assertSame('Manuella Moura', $result['byUser'][1]['name']);
        $this->assertSame('9.5000', $result['byUser'][1]['totalCredits']);
        $this->assertSame('0.0000', $result['byUser'][1]['automatedCredits']);
        $this->assertTrue($result['byUser'][2]['system']);
        $this->assertSame('1.5000', $result['byUser'][2]['totalCredits']);
    }

    private function invoke(array $query, Access $access, EntityManager $manager): object
    {
        $request = $this->createMock(Request::class);
        $request->method('getQueryParams')->willReturn($query);
        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('setHeader')->with('Cache-Control', 'private, no-store');
        return (new CreditSummary($access, new UsageSummary($manager, new Clock())))->getActionRead($request, $response);
    }
}
