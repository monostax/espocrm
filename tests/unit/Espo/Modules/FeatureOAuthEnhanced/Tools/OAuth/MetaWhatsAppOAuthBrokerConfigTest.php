<?php

namespace tests\unit\Espo\Modules\FeatureOAuthEnhanced\Tools\OAuth;

use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureOAuthEnhanced\Tools\OAuth\MetaWhatsAppOAuthBrokerConfig;
use PHPUnit\Framework\TestCase;

class MetaWhatsAppOAuthBrokerConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (['META_WHATSAPP_OAUTH_BROKER_URL', 'META_WHATSAPP_OAUTH_BROKER_TOKEN'] as $key) {
            $this->envBackup[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }

    public function testAbsentConfigDisablesClient(): void
    {
        $config = new MetaWhatsAppOAuthBrokerConfig();

        $this->assertFalse($config->isClientEnabled());
        $this->assertNull($config->getUrl());
        $this->assertNull($config->getToken());
    }

    public function testValidClientConfig(): void
    {
        putenv('META_WHATSAPP_OAUTH_BROKER_URL=https://app.monostax.ai/api/v1/MetaWhatsAppOAuthBroker/exchange');
        putenv('META_WHATSAPP_OAUTH_BROKER_TOKEN=test-token');

        $config = new MetaWhatsAppOAuthBrokerConfig();

        $this->assertTrue($config->isClientEnabled());
        $this->assertSame(
            'https://app.monostax.ai/api/v1/MetaWhatsAppOAuthBroker/exchange',
            $config->getUrl()
        );
        $this->assertSame('test-token', $config->getToken());
        $this->assertSame(
            [
                'https://app.monostax.ai/api/v1/MetaWhatsAppOAuthBroker/exchange',
                'test-token',
            ],
            $config->requireClientCredentials()
        );
    }

    public function testUrlWithoutTokenFailsClosed(): void
    {
        putenv('META_WHATSAPP_OAUTH_BROKER_URL=https://app.monostax.ai/api/v1/MetaWhatsAppOAuthBroker/exchange');

        $config = new MetaWhatsAppOAuthBrokerConfig();

        $this->expectException(Error::class);
        $config->requireClientCredentials();
    }

    public function testNonHttpsUrlRejected(): void
    {
        putenv('META_WHATSAPP_OAUTH_BROKER_URL=http://app.monostax.ai/api/v1/MetaWhatsAppOAuthBroker/exchange');

        $config = new MetaWhatsAppOAuthBrokerConfig();

        $this->expectException(Error::class);
        $config->getUrl();
    }

    public function testTokenOnlyIsValidForProductionServerMode(): void
    {
        putenv('META_WHATSAPP_OAUTH_BROKER_TOKEN=prod-token');

        $config = new MetaWhatsAppOAuthBrokerConfig();

        $this->assertFalse($config->isClientEnabled());
        $this->assertSame('prod-token', $config->getToken());
    }
}
