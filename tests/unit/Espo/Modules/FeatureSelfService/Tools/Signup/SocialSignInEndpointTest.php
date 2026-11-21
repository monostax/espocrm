<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSelfService\Tools\Signup;

use Espo\Modules\FeatureSelfService\Tools\Signup\SocialSignIn;
use PHPUnit\Framework\TestCase;

/**
 * Social sign-in trusts the ID token without checking its signature because
 * it comes straight from the provider's token endpoint over TLS. Tenant roles
 * can create OAuthProvider rows, so an endpoint they control must never be
 * used: it could return any email and log in as that user.
 */
class SocialSignInEndpointTest extends TestCase
{
    public function testOnlyTheRealProviderEndpointsAreTrusted(): void
    {
        $this->assertTrue(SocialSignIn::isTrustedTokenEndpoint('google', 'https://oauth2.googleapis.com/token'));
        $this->assertTrue(SocialSignIn::isTrustedTokenEndpoint(
            'microsoft',
            'https://login.microsoftonline.com/common/oauth2/v2.0/token',
        ));

        $this->assertFalse(SocialSignIn::isTrustedTokenEndpoint('google', 'https://attacker.example/token'));
        $this->assertFalse(SocialSignIn::isTrustedTokenEndpoint('google', 'http://oauth2.googleapis.com/token'));
        $this->assertFalse(SocialSignIn::isTrustedTokenEndpoint('google', 'https://oauth2.googleapis.com.attacker.example/token'));
        $this->assertFalse(SocialSignIn::isTrustedTokenEndpoint('google', 'https://oauth2.googleapis.com:8443/token'));
        $this->assertFalse(SocialSignIn::isTrustedTokenEndpoint('google', 'https://login.microsoftonline.com/common/oauth2/v2.0/token'));
    }

    public function testServerConfigCanAddAnExactUrl(): void
    {
        $fake = 'http://fake-chatwoot:4000/oauth/token';

        $this->assertTrue(SocialSignIn::isTrustedTokenEndpoint('google', $fake, [$fake]));
        $this->assertFalse(SocialSignIn::isTrustedTokenEndpoint('google', $fake . '?x', [$fake]));
    }
}
