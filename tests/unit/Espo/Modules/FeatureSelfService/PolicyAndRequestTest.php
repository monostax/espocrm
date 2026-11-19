<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSelfService;

use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServiceUnavailable;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\WorkspaceSlug;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PolicyAndRequestTest extends TestCase
{
    private string|false $previousEnv;

    protected function setUp(): void
    {
        $this->previousEnv = getenv(SelfServicePolicy::ENV);
    }

    protected function tearDown(): void
    {
        putenv($this->previousEnv === false
            ? SelfServicePolicy::ENV
            : SelfServicePolicy::ENV . '=' . $this->previousEnv);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function offValues(): array
    {
        return [
            'missing' => [null],
            'false' => ['false'],
            'empty' => [''],
            'uppercase' => ['TRUE'],
            'one' => ['1'],
            'padded' => [' true'],
        ];
    }

    #[DataProvider('offValues')]
    public function testOnlyTheExactStringTrueEnablesTheDeployment(?string $value): void
    {
        putenv($value === null ? SelfServicePolicy::ENV : SelfServicePolicy::ENV . '=' . $value);

        $policy = $this->policy(paused: false);

        $this->assertSame(SelfServicePolicy::STATE_DISABLED, $policy->state());
        $this->assertFalse($policy->isEnabled());
    }

    public function testEnabledWhenSwitchedOnAndNotPaused(): void
    {
        putenv(SelfServicePolicy::ENV . '=true');

        $this->assertSame(SelfServicePolicy::STATE_ENABLED, $this->policy(paused: null)->state());
        $this->assertSame(SelfServicePolicy::STATE_ENABLED, $this->policy(paused: false)->state());
    }

    public function testRuntimePauseWinsOverTheSwitch(): void
    {
        putenv(SelfServicePolicy::ENV . '=true');

        $policy = $this->policy(paused: true);

        $this->assertSame(SelfServicePolicy::STATE_PAUSED, $policy->state());

        try {
            $policy->assertEnabled();
            $this->fail('assertEnabled must throw while paused.');
        } catch (SelfServiceUnavailable $e) {
            $this->assertSame(SelfServicePolicy::STATE_PAUSED, $e->state);
            $this->assertSame('SELF_SERVICE_DISABLED', SelfServiceUnavailable::CODE);
        }
    }

    /**
     * The pause cannot turn on a deployment whose switch is off.
     */
    public function testResumingDoesNotOverrideADisabledDeployment(): void
    {
        putenv(SelfServicePolicy::ENV);

        $this->assertSame(SelfServicePolicy::STATE_DISABLED, $this->policy(paused: false)->state());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function slugs(): array
    {
        return [
            'accents' => ['Clínica São José', 'clinica-sao-jose'],
            'punctuation' => ['  Mousa & Filhos — Ltda.  ', 'mousa-filhos-ltda'],
            'nothing usable' => ['🙂 ✨', 'workspace'],
            'reserved' => ['Admin', 'admin-workspace'],
            'digits' => ['123 Imóveis', '123-imoveis'],
        ];
    }

    #[DataProvider('slugs')]
    public function testSlugsAreDnsLabels(string $name, string $slug): void
    {
        $this->assertSame($slug, WorkspaceSlug::slugify($name));
    }

    public function testLongSlugsAreCutWithoutATrailingHyphen(): void
    {
        $slug = WorkspaceSlug::slugify(str_repeat('abc ', 30));

        $this->assertLessThanOrEqual(40, strlen($slug));
        $this->assertMatchesRegularExpression('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $slug);
    }

    public function testRequestNormalizesTheNameAndHashesItsSettings(): void
    {
        $a = new ProvisioningRequest('signup:abc', 'user1', "  Acme \n Ltda  ");
        $b = new ProvisioningRequest('signup:abc', 'user1', 'Acme Ltda');
        $c = new ProvisioningRequest('signup:abc', 'user1', 'Acme Ltda', 'en_US');

        $this->assertSame('Acme Ltda', $a->workspaceName);
        $this->assertSame($a->hash(), $b->hash());
        $this->assertNotSame($a->hash(), $c->hash());
    }

    public function testAPreviewIdJoinsTheInputOnlyWhenSet(): void
    {
        $plain = new ProvisioningRequest('signup:abc', 'user1', 'Acme', ownerEmailVerified: true);
        $withPreview = new ProvisioningRequest('signup:abc', 'user1', 'Acme', ownerEmailVerified: true, previewId: 'gwd3e64sak3pvqyxzru9');

        // Requests recorded before previews existed hash exactly as before.
        $this->assertArrayNotHasKey('previewId', $plain->toArray());
        $this->assertSame(
            hash('sha256', json_encode(['signup:abc', [
                'ownerUserId' => 'user1', 'workspaceName' => 'Acme', 'language' => 'pt_BR',
                'timeZone' => 'America/Sao_Paulo', 'ownerEmailVerified' => true,
            ]], JSON_THROW_ON_ERROR)),
            $plain->hash(),
        );
        $this->assertSame('gwd3e64sak3pvqyxzru9', $withPreview->toArray()['previewId']);
        $this->assertNotSame($plain->hash(), $withPreview->hash());

        $this->expectException(InvalidArgumentException::class);
        new ProvisioningRequest('signup:abc', 'user1', 'Acme', previewId: 'linte.com');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function invalidRequests(): array
    {
        return [
            'uppercase key' => ['Signup:ABC', 'user1', 'Acme', 'pt_BR', 'America/Sao_Paulo'],
            'empty key' => ['', 'user1', 'Acme', 'pt_BR', 'America/Sao_Paulo'],
            'owner not an id' => ['signup:1', "user'1", 'Acme', 'pt_BR', 'America/Sao_Paulo'],
            'blank name' => ['signup:1', 'user1', '   ', 'pt_BR', 'America/Sao_Paulo'],
            'long name' => ['signup:1', 'user1', str_repeat('a', 101), 'pt_BR', 'America/Sao_Paulo'],
            'language' => ['signup:1', 'user1', 'Acme', 'portuguese', 'America/Sao_Paulo'],
            'time zone' => ['signup:1', 'user1', 'Acme', 'pt_BR', 'Mars/Olympus'],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidRequests(string $key, string $owner, string $name, string $language, string $timeZone): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProvisioningRequest($key, $owner, $name, $language, $timeZone);
    }

    private function policy(?bool $paused): SelfServicePolicy
    {
        $control = null;

        if ($paused !== null) {
            $control = new BaseEntity('SelfServiceControl', ['attributes' => [
                'paused' => ['type' => 'bool'],
                'reason' => ['type' => 'varchar'],
            ]]);
            $control->set('paused', $paused);
        }

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($control);

        return new SelfServicePolicy($em);
    }
}
