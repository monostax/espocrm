<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Enrichment\Access;
use Espo\Modules\FeatureCredits\Enrichment\QuoteInput;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnrichmentAccessTest extends TestCase
{
    public static function request(): array
    {
        return ['tenantId' => 'tenant', 'targetType' => 'Contact', 'targetId' => 'contact',
            'products' => ['email'], 'requestKey' => 'request-1'];
    }

    public function testReadOnlyMemberCanQuoteWithoutFieldEditAndIdentityIsRedacted(): void
    {
        [$access, $target] = $this->access();
        $this->assertSame($target, $access->target(new QuoteInput((object) self::request())));
        $identity = $access->identity($target);
        $this->assertArrayNotHasKey('cpf', $identity);
        $this->assertArrayNotHasKey('phoneNumber', $identity);
        $this->assertArrayNotHasKey('accountId', $identity);
        $this->assertArrayNotHasKey('linkedinUrl', $identity);
        $this->assertSame('person@example.test', $identity['emailAddress']);
    }

    public function testCompanyPurchaseRequiresAddressComponentReadsButNotEmailOrPhone(): void
    {
        $user = $this->createStub(User::class); $user->method('getId')->willReturn('user');
        $acl = $this->createStub(Acl::class); $acl->method('getPermissionLevel')->willReturn('yes');
        $acl->method('checkScope')->willReturn(true); $acl->method('check')->willReturn(true);
        $hidden = ['emailAddress', 'phoneNumber'];
        $acl->method('checkField')->willReturnCallback(static function ($type, $field, $action) use (&$hidden): bool {
            return $action === 'read' && !in_array($field, $hidden, true);
        });
        $tenant = new BaseEntity('Tenant', ['attributes' => ['id' => ['type' => 'varchar']]]); $tenant->set('id', 'tenant');
        $target = new BaseEntity('Account', ['attributes' => ['tenantId' => ['type' => 'varchar']]]); $target->set('tenantId', 'tenant');
        $manager = $this->createStub(EntityManager::class);
        $manager->method('getEntityById')->willReturnCallback(static fn ($type) => $type === 'Tenant' ? $tenant : $target);
        $tenants = $this->createStub(UserTenantResolver::class); $tenants->method('canActForTenant')->willReturn(true);
        $access = new Access($user, $acl, $tenants, $manager);
        $input = new QuoteInput((object) [...self::request(), 'targetType' => 'Account', 'products' => ['company']]);
        $this->assertSame($target, $access->target($input));
        $hidden[] = 'billingAddressStreet';
        $this->expectException(Forbidden::class);
        $access->target($input);
    }

    private function access(string $denial = ''): array
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn('user');
        $user->method('isPortal')->willReturn($denial === 'portal');
        $user->method('isAdmin')->willReturn($denial === 'admin-nonmember');
        $acl = $this->createStub(Acl::class);
        $acl->method('getPermissionLevel')->with('useEnrichmentCredits')->willReturn($denial === 'permission' ? 'no' : 'yes');
        $acl->method('checkScope')->with('Contact', 'read')->willReturn($denial !== 'scope');
        $acl->method('check')->willReturn($denial !== 'record');
        $acl->method('checkField')->willReturnCallback(static fn ($scope, $field, $action = 'read') =>
            $action === 'read' && in_array($field, ['firstName', 'lastName', 'emailAddress'], true) && $denial !== 'field');
        $tenants = $this->createStub(UserTenantResolver::class);
        $tenants->method('canActForTenant')->with($user, 'tenant')->willReturn(!in_array($denial, ['membership', 'admin-nonmember'], true));
        $data = ['id' => 'contact', 'tenantId' => $denial === 'foreign' ? 'other' : 'tenant',
            'deleted' => $denial === 'deleted', 'firstName' => 'Person', 'lastName' => 'Example',
            'emailAddress' => 'person@example.test', 'phoneNumber' => '+5511999999999',
            'cpf' => '52998224725', 'accountId' => 'related', 'linkedinUrl' => 'https://www.linkedin.com/in/person'];
        $target = new BaseEntity('Contact', ['attributes' => array_fill_keys(array_keys($data), ['type' => 'varchar'])]);
        $target->set($data);
        $tenant = new BaseEntity('Tenant', ['attributes' => ['id' => ['type' => 'varchar'], 'deleted' => ['type' => 'bool']]]);
        $tenant->set(['id' => 'tenant', 'deleted' => $denial === 'tenant-deleted']);
        $manager = $this->createStub(EntityManager::class);
        $manager->method('getEntityById')->willReturnCallback(static fn ($type) => $type === 'Tenant' ? $tenant : ($denial === 'missing' ? null : $target));
        return [new Access($user, $acl, $tenants, $manager), $target];
    }

    #[DataProvider('denials')]
    public function testDeniedAccessCannotUseEnrichment(string $reason): void
    {
        [$access] = $this->access($reason);
        $this->expectException(Forbidden::class);
        $access->target(new QuoteInput((object) self::request()));
    }

    public static function denials(): iterable
    {
        foreach (['portal', 'permission', 'scope', 'record', 'field', 'membership', 'admin-nonmember',
            'foreign', 'deleted', 'tenant-deleted', 'missing'] as $reason) yield [$reason];
    }

    #[DataProvider('invalidInputs')]
    public function testClientCannotForgeTermsOrSettings(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QuoteInput((object) $input);
    }

    public static function invalidInputs(): iterable
    {
        foreach (['credits' => '1', 'policyId' => 'forged', 'actorId' => 'admin', 'identity' => [],
            'waterfall' => 'true', 'refresh' => 1, 'targetType' => 'Lead', 'budget' => 1.1,
            'products' => ['phone', 'email'], 'requestKey' => str_repeat('a', 65)] as $field => $value) {
            yield $field => [[...self::request(), $field => $value]];
        }
        yield 'waterfall without budget' => [[...self::request(), 'waterfall' => true]];
        yield 'zero budget' => [[...self::request(), 'budget' => '0']];
        yield 'negative budget' => [[...self::request(), 'budget' => '-1']];
        yield 'company on contact' => [[...self::request(), 'products' => ['company']]];
    }
}
