<?php

namespace tests\unit\Espo\Modules\FeatureMetaWhatsAppBusiness;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\TemplateAccess;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppOAuthHelper;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class TemplateAccessTest extends TestCase
{
    private function entity(array $values): Entity
    {
        $entity = $this->createStub(Entity::class);
        $entity->method('get')->willReturnCallback(fn ($key) => $values[$key] ?? null);
        return $entity;
    }

    /** @dataProvider deniedContexts */
    public function testDeniedContextsNeverResolveCredentialSecrets(bool $scope, bool $credentialRead, bool $oauthRead): void
    {
        $credential = $this->entity(['isActive' => true, 'oAuthAccountId' => 'oauth']);
        $oauth = $this->entity(['providerId' => 'provider']);
        $provider = $this->entity(['isActive' => true, 'provider' => 'meta-whatsapp']);
        $em = $this->createStub(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type) =>
            ['Credential' => $credential, 'OAuthAccount' => $oauth, 'OAuthProvider' => $provider][$type]);
        $acl = $this->createStub(Acl::class);
        $acl->method('checkScope')->willReturn($scope);
        $acl->method('check')->willReturnCallback(fn ($record) => $record === $credential ? $credentialRead : $oauthRead);
        $resolver = $this->createMock(CredentialResolver::class);
        $resolver->expects($this->never())->method('resolve');
        $access = new TemplateAccess($em, $acl, new WhatsAppOAuthHelper($em, $acl), $resolver);
        $this->expectException(Forbidden::class);
        $access->resolve(['credentialId' => 'credential']);
    }

    public static function deniedContexts(): array { return [[false, true, true], [true, false, true], [true, true, false]]; }

    public function testCredentialCannotOverrideWabaEvenWithReadableOAuth(): void
    {
        $em = $this->createStub(EntityManager::class);
        $em->method('getEntityById')->willReturn($this->entity(['isActive' => true, 'oAuthAccountId' => 'oauth']));
        $acl = $this->createStub(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('check')->willReturn(true);
        $helper = $this->createStub(WhatsAppOAuthHelper::class);
        $helper->method('validateTemplateAccountAccess')->willReturn($this->entity([]));
        $resolver = $this->createStub(CredentialResolver::class);
        $resolver->method('resolve')->willReturn((object) ['businessAccountId' => '123']);
        $this->expectException(Forbidden::class);
        (new TemplateAccess($em, $acl, $helper, $resolver))->resolve(['credentialId' => 'credential', 'wabaId' => '456']);
    }

    public function testAmbiguousContextsAreRejectedBeforeResolution(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->never())->method('getEntityById');
        $acl = $this->createStub(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $this->expectException(BadRequest::class);
        (new TemplateAccess($em, $acl, $this->createStub(WhatsAppOAuthHelper::class),
            $this->createStub(CredentialResolver::class)))->resolve(['credentialId' => 'credential', 'oAuthAccountId' => 'oauth']);
    }

    public function testDirectReadersNeedNoTemplateWriteGrant(): void
    {
        $em = $this->createStub(EntityManager::class);
        $acl = $this->createMock(Acl::class);
        $acl->expects($this->once())->method('checkScope')->with('WhatsAppBusinessAccount', 'read')->willReturn(true);
        $helper = $this->createMock(WhatsAppOAuthHelper::class);
        $helper->expects($this->once())->method('validateTemplateAccountAccess')->with('oauth')->willReturn($this->entity([]));
        $context = (new TemplateAccess($em, $acl, $helper, $this->createStub(CredentialResolver::class)))
            ->resolve(['oAuthAccountId' => 'oauth', 'businessAccountId' => '123']);
        $this->assertSame('123', $context['businessAccountId']);
    }

    /** @dataProvider unavailableProviders */
    public function testInactiveOrUnsupportedProvidersCannotManage(bool $active, string $type): void
    {
        $em = $this->createStub(EntityManager::class);
        $oauth = $this->entity(['providerId' => 'provider']);
        $provider = $this->entity(['isActive' => $active, 'provider' => $type]);
        $em->method('getEntityById')->willReturnCallback(fn ($type) => $type === 'OAuthAccount' ? $oauth : $provider);
        $acl = $this->createStub(Acl::class); $acl->method('check')->willReturn(true);
        $this->expectException(BadRequest::class);
        (new WhatsAppOAuthHelper($em, $acl))->validateTemplateAccountAccess('oauth');
    }

    public static function unavailableProviders(): array { return [[false, 'meta-whatsapp'], [true, 'google']]; }
}
