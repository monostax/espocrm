<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Rebuild;

use Espo\Modules\Global\Rebuild\SeedRole;
use PHPUnit\Framework\TestCase;

/**
 * Accounts are the B2B pack: only tenant-b2b grants them. The base roles must
 * disable Account explicitly (an unset scope would get Espo's default level),
 * and the CRM sidebar must hide its Account links from users without access.
 */
class SeedRoleB2bPackTest extends TestCase
{
    public function testBaseRolesDisableAccount(): void
    {
        foreach (['tenant', 'tenant-admin', 'tenant-user-api'] as $staticId) {
            $role = $this->role($staticId);

            $this->assertArrayHasKey('Account', $role['data'], $staticId);
            $this->assertFalse($role['data']['Account'], "{$staticId} must not grant Account");
            $this->assertSame(
                'no',
                $role['fieldData']['Opportunity']->account->read ?? null,
                "{$staticId} must hide Opportunity.account",
            );
        }
    }

    public function testB2bRoleGrantsAccountAndTheOpportunityAccountField(): void
    {
        $role = $this->role('tenant-b2b');

        $this->assertSame(
            ['create' => 'yes', 'read' => 'team', 'edit' => 'team', 'delete' => 'team', 'stream' => 'team'],
            $role['data']['Account'],
        );
        $this->assertSame('yes', $role['fieldData']['Opportunity']->account->read);
        $this->assertSame('yes', $role['fieldData']['Opportunity']->account->edit);
    }

    public function testEveryAccountSidebarLinkRequiresAccountAccess(): void
    {
        $path = dirname(__DIR__, 6) . '/custom/Espo/Modules/Global/Rebuild/SeedSidenavConfig.php';
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $accountLinks = 0;

        foreach ($lines as $index => $line) {
            if (!str_contains($line, "'url' => '#Account")) {
                continue;
            }

            $accountLinks++;
            $this->assertStringContainsString(
                "'aclScope' => 'Account'",
                $lines[$index + 1] ?? '',
                'Account link on line ' . ($index + 1) . ' must carry aclScope',
            );
        }

        $this->assertGreaterThan(0, $accountLinks);
    }

    private function role(string $staticId): array
    {
        $seed = (new \ReflectionClass(SeedRole::class))->newInstanceWithoutConstructor();
        $definitions = (new \ReflectionMethod(SeedRole::class, 'getRoleDefinitions'))->invoke($seed);
        $matches = array_values(array_filter($definitions, fn (array $role) => $role['staticId'] === $staticId));

        $this->assertCount(1, $matches, $staticId);

        return $matches[0];
    }
}
