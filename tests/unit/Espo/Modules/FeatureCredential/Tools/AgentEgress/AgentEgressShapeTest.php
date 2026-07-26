<?php

namespace tests\unit\Espo\Modules\FeatureCredential\Tools\AgentEgress;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCredential\Tools\AgentEgress\AgentEgressShape;
use PHPUnit\Framework\TestCase;
use stdClass;

class AgentEgressShapeTest extends TestCase
{
    public function testIsValidDotPath(): void
    {
        $this->assertTrue(AgentEgressShape::isValidDotPath('accessToken'));
        $this->assertTrue(AgentEgressShape::isValidDotPath('access_token'));
        $this->assertTrue(AgentEgressShape::isValidDotPath('data.someKey'));
        $this->assertTrue(AgentEgressShape::isValidDotPath('a.b.c'));

        $this->assertFalse(AgentEgressShape::isValidDotPath(''));
        $this->assertFalse(AgentEgressShape::isValidDotPath('.x'));
        $this->assertFalse(AgentEgressShape::isValidDotPath('x.'));
        $this->assertFalse(AgentEgressShape::isValidDotPath('a..b'));
        $this->assertFalse(AgentEgressShape::isValidDotPath('1bad'));
        $this->assertFalse(AgentEgressShape::isValidDotPath('has-dash'));
        $this->assertFalse(AgentEgressShape::isValidDotPath('a b'));
    }

    public function testReadPath(): void
    {
        $bag = [
            'accessToken' => 'tok',
            'data' => [
                'nested' => [
                    'key' => 'val',
                ],
            ],
            'empty' => '',
            'num' => 42,
        ];

        $this->assertSame('tok', AgentEgressShape::readPath($bag, 'accessToken'));
        $this->assertSame('val', AgentEgressShape::readPath($bag, 'data.nested.key'));
        $this->assertSame('42', AgentEgressShape::readPath($bag, 'num'));
        $this->assertNull(AgentEgressShape::readPath($bag, 'empty'));
        $this->assertNull(AgentEgressShape::readPath($bag, 'missing'));
        $this->assertNull(AgentEgressShape::readPath($bag, 'data.nested'));

        $obj = new stdClass();
        $obj->username = 'u';
        $this->assertSame('u', AgentEgressShape::readPath($obj, 'username'));
    }

    public function testParseAndValidateShapeRejectsBadEnv(): void
    {
        $this->expectException(BadRequest::class);

        AgentEgressShape::parseAndValidateShape((object) [
            'enabled' => true,
            'secrets' => [
                (object) [
                    'envName' => 'bad-name',
                    'configPath' => 'accessToken',
                    'hosts' => ['api.example.com'],
                ],
            ],
        ]);
    }

    public function testParseAndValidateShapeAcceptsValid(): void
    {
        $out = AgentEgressShape::parseAndValidateShape([
            'enabled' => true,
            'placeholderMode' => 'shared',
            'secrets' => [
                [
                    'envName' => 'ACCESS_TOKEN',
                    'configPath' => 'accessToken',
                    'hosts' => 'graph.instagram.com, *.fb.com',
                    'replaceInQuery' => true,
                ],
            ],
        ]);

        $this->assertNotNull($out);
        $this->assertTrue($out->enabled);
        $this->assertCount(1, $out->secrets);
        $this->assertSame('ACCESS_TOKEN', $out->secrets[0]->envName);
        $this->assertSame(['graph.instagram.com', '*.fb.com'], $out->secrets[0]->hosts);
        $this->assertTrue($out->secrets[0]->replaceInQuery);
    }

    public function testDisabledSkipsSecretRequirements(): void
    {
        $out = AgentEgressShape::parseAndValidateShape((object) [
            'enabled' => false,
            'secrets' => [],
        ]);

        $this->assertNotNull($out);
        $this->assertFalse($out->enabled);
    }
}
