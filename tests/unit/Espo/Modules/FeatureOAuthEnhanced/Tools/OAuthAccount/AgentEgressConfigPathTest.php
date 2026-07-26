<?php

namespace tests\unit\Espo\Modules\FeatureOAuthEnhanced\Tools\OAuthAccount;

use Espo\Modules\FeatureOAuthEnhanced\Tools\OAuthAccount\AgentEgressConfigPath;
use PHPUnit\Framework\TestCase;

class AgentEgressConfigPathTest extends TestCase
{
    public function testAllowedExactAndData(): void
    {
        $this->assertTrue(AgentEgressConfigPath::isAllowed('accessToken'));
        $this->assertTrue(AgentEgressConfigPath::isAllowed('access_token'));
        $this->assertTrue(AgentEgressConfigPath::isAllowed('refreshToken'));
        $this->assertTrue(AgentEgressConfigPath::isAllowed('refresh_token'));
        $this->assertTrue(AgentEgressConfigPath::isAllowed('data.foo'));
        $this->assertTrue(AgentEgressConfigPath::isAllowed('data.foo.bar'));
    }

    public function testDisallowedRoots(): void
    {
        $this->assertFalse(AgentEgressConfigPath::isAllowed('name'));
        $this->assertFalse(AgentEgressConfigPath::isAllowed('providerId'));
        $this->assertFalse(AgentEgressConfigPath::isAllowed('userId'));
        $this->assertFalse(AgentEgressConfigPath::isAllowed('expiresAt'));
        $this->assertFalse(AgentEgressConfigPath::isAllowed('data'));
        $this->assertFalse(AgentEgressConfigPath::isAllowed('data.'));
        $this->assertFalse(AgentEgressConfigPath::isAllowed('config.accessToken'));
        $this->assertFalse(AgentEgressConfigPath::isAllowed(''));
    }
}
