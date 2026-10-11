<?php
declare(strict_types=1);
namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Controllers\CreditConfiguration;
use Espo\Modules\FeatureCredits\Pricing\AiCatalog;
use Espo\Modules\FeatureCredits\Services\Configuration;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class CreditConfigurationTest extends TestCase
{
    private function controller(bool $admin): CreditConfiguration
    {
        $user = $this->createStub(User::class); $user->method('isAdmin')->willReturn($admin);
        $user->method('getId')->willReturn('admin');
        $manager = $this->createMock(EntityManager::class); $manager->expects($this->never())->method('getPDO');
        return new CreditConfiguration($user, new Configuration($manager, $this->createStub(Clock::class), new AiCatalog($manager)));
    }

    public function testNonAdministratorCannotReadInputOrPublishTerms(): void
    {
        $request = $this->createMock(Request::class); $request->expects($this->never())->method('getBodyContents');
        $this->expectException(Forbidden::class);
        $this->controller(false)->postActionPublish($request, $this->createStub(Response::class));
    }

    public function testMalformedAndForgedCommandsFailBeforeDatabaseWork(): void
    {
        foreach (['null', '{}', '{', '{"operation":"grant","data":{}}', '{"operation":"publishModel","data":{}}',
            '{"operation":"publishAgreement","data":{},"actorId":"other"}',
            '{"operation":"replaceAgreement","data":{}}',
            '{"operation":"publishModel","data":{"provider":"google","model":"fixture","multiplier":"9999999999","inputTokenBound":9007199254740991,"outputTokenLimit":1,"boundProfile":"fixture"}}'] as $raw) {
            $request = $this->createStub(Request::class); $request->method('getBodyContents')->willReturn($raw);
            try { $this->controller(true)->postActionPublish($request, $this->createStub(Response::class)); $this->fail('Bad command accepted.'); }
            catch (BadRequest) { $this->addToAssertionCount(1); }
        }
    }
}
