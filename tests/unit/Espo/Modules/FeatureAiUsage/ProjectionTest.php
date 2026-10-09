<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAiUsage;

use Espo\Core\Acl;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Projection;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class ProjectionTest extends TestCase
{
    public function testRecordLabelsAndLinksRequireRecordReadAccess(): void
    {
        $em = $this->createMock(EntityManager::class);
        $record = $this->createMock(Entity::class);
        $record->expects($this->never())->method('get');
        $em->method('getEntityById')->willReturn($record);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('check')->willReturn(false);
        $projection = new Projection($em, $acl, $this->createMock(SelectBuilderFactory::class), $this->createMock(User::class));
        $this->assertNull($projection->record('Opportunity', 'private'));
    }

    public function testForbiddenNameFieldNeverLeaksThroughAReadableLink(): void
    {
        $record = $this->createMock(Entity::class);
        $record->expects($this->never())->method('get');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($record);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('check')->willReturn(true);
        $acl->method('checkField')->willReturn(false);
        $projection = new Projection($em, $acl, $this->createMock(SelectBuilderFactory::class), $this->createMock(User::class));
        $this->assertSame(['id' => 'o', 'scope' => 'Opportunity', 'name' => null], $projection->record('Opportunity', 'o'));
    }

    public function testActivityProjectionHonorsFieldAndLinkPermissions(): void
    {
        $acl = $this->createMock(Acl::class);
        $acl->method('checkField')->willReturnCallback(fn ($scope, $field) => in_array($field, ['runAt', 'kind', 'wasTransferred'], true));
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->never())->method('getEntityById');
        $projection = new Projection($em, $acl, $this->createMock(SelectBuilderFactory::class), $this->createMock(User::class));
        $row = $projection->activity([
            'id' => 'r', 'day' => '2026-09-01', 'runAt' => '2026-09-01 10:00:00', 'kind' => 'customer-message',
            'model' => 'secret-model', 'conversationId' => 'c', 'toolsUsed' => ['secret-tool'], 'wasTransferred' => true,
        ]);
        $this->assertNull($row['model']);
        $this->assertNull($row['conversation']);
        $this->assertSame(['transfer'], $row['actions']);
    }

    public function testTokensRequireInstanceAdminEvenWhenFieldPermissionsAllowReading(): void
    {
        foreach ([false, true] as $isAdmin) {
            $user = $this->createMock(User::class);
            $user->method('isAdmin')->willReturn($isAdmin);
            $acl = $this->createMock(Acl::class);
            $acl->method('checkField')->willReturn(true);
            $projection = new Projection($this->createMock(EntityManager::class), $acl, $this->createMock(SelectBuilderFactory::class), $user);
            foreach ([null, ['billing' => ['covered' => 1, 'overage' => 0, 'charges' => []]]] as $group) {
                $tokens = ['inputTokens' => 1234, 'outputTokens' => 0, 'cachedInputTokens' => null];
                $row = $projection->activity(['id' => 'r', 'day' => '2026-10-08'] + $tokens, $group);
                $this->assertSame($isAdmin, array_key_exists('analytics', $row));
                foreach (\Espo\Modules\FeatureAiUsage\Services\Analytics::FIELDS as $field) {
                    $this->assertSame($isAdmin, array_key_exists($field, $row));
                }
                foreach ($tokens as $field => $value) {
                    if ($isAdmin) {
                        $this->assertArrayHasKey($field, $row);
                        $this->assertSame($value, $row[$field]);
                    } else {
                        $this->assertArrayNotHasKey($field, $row);
                    }
                }
            }
        }
    }

    public function testFailedActivityIsNotBilledEvenWhenItsDailyGroupHasCharges(): void
    {
        $projection = new Projection($this->createMock(EntityManager::class), $this->createMock(Acl::class), $this->createMock(SelectBuilderFactory::class), $this->createMock(User::class));
        $group = ['billing' => ['covered' => 0, 'overage' => 2, 'charges' => ['BRL' => ['amount' => 0.98]]]];
        foreach (['' => 'billed', 'completed' => 'billed', 'cancelled' => 'billed', 'superseded' => 'billed', 'failed' => 'failed'] as $outcome => $expected) {
            $row = ['id' => 'r', 'day' => '2026-09-01', 'runOutcome' => $outcome];
            $this->assertSame($expected, $projection->activity($row, $group)['billingStatus']);
        }
        $this->assertSame('failed', $projection->activity(['id' => 'r', 'day' => '2026-09-01', 'runOutcome' => 'failed'])['billingStatus']);
        $this->assertSame('waived', $projection->activity(['id' => 'r', 'day' => '2026-09-01', 'billingWaived' => true], $group)['billingStatus']);
        $this->assertSame('pending', $projection->activity(['id' => 'r', 'day' => '2026-09-01'])['billingStatus']);
    }
}
