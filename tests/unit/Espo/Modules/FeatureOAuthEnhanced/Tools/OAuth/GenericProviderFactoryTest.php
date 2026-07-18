<?php

namespace tests\unit\Espo\Modules\FeatureOAuthEnhanced\Tools\OAuth;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Crypt;
use Espo\Entities\OAuthProvider;
use Espo\Modules\FeatureOAuthEnhanced\Services\MetaWhatsAppOAuthBrokerService;
use Espo\Modules\FeatureOAuthEnhanced\Tools\OAuth\GenericProviderFactory;
use Espo\Modules\FeatureOAuthEnhanced\Tools\OAuth\MetaWhatsAppOAuthBrokerConfig;
use Espo\Tools\OAuth\ConfigDataProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class GenericProviderFactoryTest extends TestCase
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

    public function testBrokerModeUsesBrokerUrlAndDoesNotDecryptSecret(): void
    {
        putenv('META_WHATSAPP_OAUTH_BROKER_URL=https://app.monostax.ai/api/v1/MetaWhatsAppOAuthBroker/exchange');
        putenv('META_WHATSAPP_OAUTH_BROKER_TOKEN=broker-token');

        $crypt = $this->createMock(Crypt::class);
        $crypt->expects($this->never())->method('decrypt');

        $factory = $this->makeFactory($crypt);
        $provider = $this->makeProvider(
            MetaWhatsAppOAuthBrokerService::PROVIDER_ID,
            'meta-whatsapp-coexistence',
            '1914611219187440',
            null
        );

        $generic = $factory->create($provider);

        $this->assertInstanceOf(GenericProvider::class, $generic);
        $this->assertSame(
            'https://app.monostax.ai/api/v1/MetaWhatsAppOAuthBroker/exchange',
            $this->readProtected($generic, 'urlAccessToken')
        );
    }

    public function testOnlyCoexistenceProviderIdUsesBroker(): void
    {
        putenv('META_WHATSAPP_OAUTH_BROKER_URL=https://app.monostax.ai/api/v1/MetaWhatsAppOAuthBroker/exchange');
        putenv('META_WHATSAPP_OAUTH_BROKER_TOKEN=broker-token');

        $crypt = $this->createMock(Crypt::class);
        $crypt->expects($this->once())
            ->method('decrypt')
            ->with('enc-secret')
            ->willReturn('meta-secret');

        $factory = $this->makeFactory($crypt);
        $provider = $this->makeProvider(
            'msx_meta_wa_01',
            'meta-whatsapp',
            '1914611219187440',
            'enc-secret'
        );

        $generic = $factory->create($provider);

        $this->assertSame(
            'https://graph.facebook.com/v22.0/oauth/access_token',
            $this->readProtected($generic, 'urlAccessToken')
        );
    }

    public function testProductionWithoutBrokerUrlUsesDirectExchange(): void
    {
        putenv('META_WHATSAPP_OAUTH_BROKER_TOKEN=prod-only-token');

        $crypt = $this->createMock(Crypt::class);
        $crypt->expects($this->once())
            ->method('decrypt')
            ->with('enc-secret')
            ->willReturn('meta-secret');

        $factory = $this->makeFactory($crypt);
        $provider = $this->makeProvider(
            MetaWhatsAppOAuthBrokerService::PROVIDER_ID,
            'meta-whatsapp-coexistence',
            '1914611219187440',
            'enc-secret'
        );

        $generic = $factory->create($provider);

        $this->assertSame(
            'https://graph.facebook.com/v22.0/oauth/access_token',
            $this->readProtected($generic, 'urlAccessToken')
        );
        $this->assertNull($this->readProtected($generic, 'redirectUri'));
    }

    public function testUrlWithoutTokenFailsClosed(): void
    {
        putenv('META_WHATSAPP_OAUTH_BROKER_URL=https://app.monostax.ai/api/v1/MetaWhatsAppOAuthBroker/exchange');

        $crypt = $this->createMock(Crypt::class);
        $crypt->expects($this->never())->method('decrypt');

        $factory = $this->makeFactory($crypt);
        $provider = $this->makeProvider(
            MetaWhatsAppOAuthBrokerService::PROVIDER_ID,
            'meta-whatsapp-coexistence',
            '1914611219187440',
            null
        );

        $this->expectException(Error::class);
        $factory->create($provider);
    }

    public function testCreateDirectNeverUsesBroker(): void
    {
        putenv('META_WHATSAPP_OAUTH_BROKER_URL=https://app.monostax.ai/api/v1/MetaWhatsAppOAuthBroker/exchange');
        putenv('META_WHATSAPP_OAUTH_BROKER_TOKEN=broker-token');

        $crypt = $this->createMock(Crypt::class);
        $crypt->expects($this->once())
            ->method('decrypt')
            ->with('enc-secret')
            ->willReturn('meta-secret');

        $factory = $this->makeFactory($crypt);
        $provider = $this->makeProvider(
            MetaWhatsAppOAuthBrokerService::PROVIDER_ID,
            'meta-whatsapp-coexistence',
            '1914611219187440',
            'enc-secret'
        );

        $generic = $factory->createDirect($provider);

        $this->assertSame(
            'https://graph.facebook.com/v22.0/oauth/access_token',
            $this->readProtected($generic, 'urlAccessToken')
        );
    }

    private function makeFactory(Crypt $crypt): GenericProviderFactory
    {
        $configDataProvider = $this->createMock(ConfigDataProvider::class);
        $configDataProvider->method('getRedirectUri')
            ->willReturn('https://app.monostax.ai/oauth-callback.php');

        return new GenericProviderFactory(
            $configDataProvider,
            $crypt,
            new MetaWhatsAppOAuthBrokerConfig()
        );
    }

    private function makeProvider(
        string $id,
        string $providerType,
        string $clientId,
        ?string $encryptedSecret,
    ): OAuthProvider {
        $provider = $this->getMockBuilder(OAuthProvider::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'get', 'getClientId', 'getClientSecret', 'getTokenEndpoint'])
            ->getMock();

        $provider->method('getId')->willReturn($id);
        $provider->method('getClientId')->willReturn($clientId);
        $provider->method('getTokenEndpoint')
            ->willReturn('https://graph.facebook.com/v22.0/oauth/access_token');

        if ($encryptedSecret === null) {
            $provider->method('getClientSecret')
                ->willThrowException(new \UnexpectedValueException('No client secret.'));
        } else {
            $provider->method('getClientSecret')->willReturn($encryptedSecret);
        }

        $provider->method('get')->willReturnCallback(
            function (string $field) use ($providerType, $clientId) {
                return match ($field) {
                    'provider' => $providerType,
                    'clientId' => $clientId,
                    default => null,
                };
            }
        );

        return $provider;
    }

    private function readProtected(object $object, string $property): mixed
    {
        $ref = new ReflectionClass($object);

        while ($ref && !$ref->hasProperty($property)) {
            $ref = $ref->getParentClass();
        }

        $this->assertNotNull($ref);

        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($object);
    }
}
