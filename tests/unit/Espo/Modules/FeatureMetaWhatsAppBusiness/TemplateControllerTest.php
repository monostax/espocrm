<?php

namespace tests\unit\Espo\Modules\FeatureMetaWhatsAppBusiness;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Controllers\WhatsAppBusinessAccountMessageTemplate as Controller;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\TemplateAccess;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppBusinessAccountMessageTemplate as Service;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDeadline;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateFailure;
use PHPUnit\Framework\TestCase;

class TemplateControllerTest extends TestCase
{
    private function request(): Request
    {
        $request = $this->createStub(Request::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getHeader')->willReturn('application/json');
        $request->method('getParsedBody')->willReturn((object) [
            'credentialId' => 'credential', 'definition' => TemplateDefinitionTest::definition(),
        ]);
        return $request;
    }

    public function testAcceptedOperationLogsSafeResolvedIdentifiersWithoutRefreshing(): void
    {
        $service = $this->createMock(Service::class);
        $service->expects($this->once())->method('mutate')->with('create', [
            'definition' => TemplateDefinitionTest::definition(), 'oAuthAccountId' => 'oauth', 'businessAccountId' => '123',
        ], null, $this->isInstanceOf(TemplateDeadline::class))->willReturn((object) ['accepted' => true, 'templateId' => '456']);
        $service->expects($this->never())->method('listPage');
        $access = $this->createMock(TemplateAccess::class);
        $access->expects($this->once())->method('resolve')->willReturn(['oAuthAccountId' => 'oauth', 'businessAccountId' => '123']);
        $user = $this->createStub(User::class); $user->method('getId')->willReturn('actor');
        $log = $this->createMock(Log::class);
        $log->expects($this->once())->method('info')->willReturnCallback(function ($message, $context) {
            $this->assertSame('actor', $context['actor']);
            $this->assertSame('oauth', $context['oAuthAccountId']);
            $this->assertSame('123', $context['businessAccountId']);
            $this->assertSame('456', $context['templateId']);
            $this->assertSame('accepted', $context['outcome']);
            $this->assertSame(24, strlen($context['correlationId']));
            $this->assertStringNotContainsString('Order', json_encode($context));
        });
        $result = (new Controller($service, $access, $log, $user))->postActionCreateTemplate(
            $this->request(), $this->createStub(Response::class)
        );
        $this->assertTrue($result->accepted);
    }

    public function testAccessFailureIsExplicitAndNeverReachesMutation(): void
    {
        $service = $this->createMock(Service::class); $service->expects($this->never())->method('mutate');
        $access = $this->createStub(TemplateAccess::class);
        $access->method('resolve')->willThrowException(new Forbidden('No read access.'));
        $response = $this->createMock(Response::class); $response->expects($this->once())->method('setStatus')->with(403)->willReturnSelf();
        $user = $this->createStub(User::class); $user->method('getId')->willReturn('actor');
        $result = (new Controller($service, $access, $this->createStub(Log::class), $user))->postActionCreateTemplate($this->request(), $response);
        $this->assertSame('access', $result->error);
    }

    public function testUnknownOutcomeIsReturnedWithoutRetry(): void
    {
        $service = $this->createMock(Service::class);
        $service->expects($this->once())->method('mutate')->willThrowException(new TemplateFailure(
            'uncertain', 'Refresh before retrying.', 504, 1, 2
        ));
        $access = $this->createStub(TemplateAccess::class);
        $access->method('resolve')->willReturn(['oAuthAccountId' => 'oauth', 'businessAccountId' => '123']);
        $response = $this->createMock(Response::class); $response->expects($this->once())->method('setStatus')->with(504)->willReturnSelf();
        $user = $this->createStub(User::class); $user->method('getId')->willReturn('actor');
        $result = (new Controller($service, $access, $this->createStub(Log::class), $user))->postActionCreateTemplate($this->request(), $response);
        $this->assertSame('uncertain', $result->error);
        $this->assertSame(1, $result->metaCode);
        $this->assertSame(2, $result->metaSubcode);
    }
}
