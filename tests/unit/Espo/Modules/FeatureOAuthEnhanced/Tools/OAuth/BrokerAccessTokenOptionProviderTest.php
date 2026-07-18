<?php

namespace tests\unit\Espo\Modules\FeatureOAuthEnhanced\Tools\OAuth;

use Espo\Modules\FeatureOAuthEnhanced\Tools\OAuth\BrokerAccessTokenOptionProvider;
use League\OAuth2\Client\Provider\AbstractProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BrokerAccessTokenOptionProviderTest extends TestCase
{
    public function testShapesSafeBrokerRequest(): void
    {
        $provider = new BrokerAccessTokenOptionProvider('broker-secret');

        $options = $provider->getAccessTokenOptions(AbstractProvider::METHOD_POST, [
            'client_id' => 'should-be-stripped',
            'client_secret' => 'should-be-stripped',
            'redirect_uri' => 'https://example.test/callback',
            'grant_type' => 'authorization_code',
            'code' => 'meta-one-time-code',
            'refresh_token' => 'nope',
        ]);

        $this->assertSame('application/x-www-form-urlencoded', $options['headers']['content-type']);
        $this->assertSame('application/json', $options['headers']['accept']);
        $this->assertSame('Bearer broker-secret', $options['headers']['authorization']);

        parse_str($options['body'], $body);

        $this->assertSame(
            [
                'grant_type' => 'authorization_code',
                'code' => 'meta-one-time-code',
            ],
            $body
        );
        $this->assertArrayNotHasKey('client_id', $body);
        $this->assertArrayNotHasKey('client_secret', $body);
        $this->assertArrayNotHasKey('redirect_uri', $body);
        $this->assertArrayNotHasKey('refresh_token', $body);
    }

    public function testRejectsNonAuthorizationCodeGrant(): void
    {
        $provider = new BrokerAccessTokenOptionProvider('broker-secret');

        $this->expectException(RuntimeException::class);

        $provider->getAccessTokenOptions(AbstractProvider::METHOD_POST, [
            'grant_type' => 'refresh_token',
            'refresh_token' => 'x',
        ]);
    }
}
