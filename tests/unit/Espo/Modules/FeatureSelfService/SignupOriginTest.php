<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSelfService;

use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\Modules\FeatureSelfService\Tools\Signup\SignupOrigin;
use Espo\Modules\FeatureSelfService\Tools\Signup\SignupRequest;
use PHPUnit\Framework\TestCase;

class SignupOriginTest extends TestCase
{
    public function testKeepsOnlyKnownValues(): void
    {
        $origin = SignupOrigin::fromInput('agente', 'qr', (object) [
            'utm_source' => ' chatgpt ',
            'utm_content' => "a1\nb",
            'utm_campaign' => '',
            'gclid' => str_repeat('x', 300),
            'password' => 'nope',
            'utm_term' => ['not', 'a', 'string'],
        ]);

        $this->assertSame('agente', $origin->job);
        $this->assertSame('qr', $origin->connect);
        // Known keys only, trimmed, control characters gone, capped at 200, in the known keys' order.
        $this->assertSame(['utm_source', 'utm_content', 'gclid'], array_keys($origin->attribution));
        $this->assertSame('chatgpt', $origin->attribution['utm_source']);
        $this->assertSame('a1 b', $origin->attribution['utm_content']);
        $this->assertSame(200, mb_strlen($origin->attribution['gclid']));
    }

    public function testDropsUnknownJobsAndConnects(): void
    {
        $origin = SignupOrigin::fromInput('emdia', 'coexistence', 'utm_source=x');

        $this->assertNull($origin->job);
        $this->assertNull($origin->connect);
        $this->assertSame([], $origin->attribution);
        $this->assertTrue($origin->isEmpty());
        $this->assertSame([], $origin->toArray());
    }

    public function testRoundTripsThroughAStoredRecord(): void
    {
        $origin = SignupOrigin::fromInput('funil', 'api', ['utm_source' => 'chatgpt']);
        $stored = json_decode((string) json_encode($origin->toArray()));

        $this->assertEquals($origin, SignupOrigin::fromArray((array) $stored));
    }

    public function testTheSignupRequestCarriesIt(): void
    {
        $args = ['Maria', 'm@example.com', '11912345678', 'Acme', 'segredo-forte', true, 'pt_BR', ''];
        $origin = SignupOrigin::fromInput('whatsapp', 'api', []);

        $this->assertSame($origin, (new SignupRequest(...[...$args, $origin]))->origin);
        $this->assertTrue((new SignupRequest(...$args))->origin->isEmpty());
    }

    public function testJoinsTheProvisioningInputOnlyWhenSet(): void
    {
        $plain = new ProvisioningRequest('signup:abc', 'user1', 'Acme', ownerEmailVerified: true);
        $empty = new ProvisioningRequest('signup:abc', 'user1', 'Acme', ownerEmailVerified: true, origin: SignupOrigin::none());
        $withOrigin = new ProvisioningRequest('signup:abc', 'user1', 'Acme', ownerEmailVerified: true,
            origin: SignupOrigin::fromInput('agente', 'qr', ['utm_content' => 'a1']));

        // Requests recorded before origins existed hash exactly as before.
        $this->assertSame($plain->hash(), $empty->hash());
        $this->assertArrayNotHasKey('job', $plain->toArray());
        $this->assertSame(
            ['job' => 'agente', 'connect' => 'qr', 'attribution' => ['utm_content' => 'a1']],
            array_intersect_key($withOrigin->toArray(), array_flip(['job', 'connect', 'attribution'])),
        );
        $this->assertNotSame($plain->hash(), $withOrigin->hash());
    }
}
