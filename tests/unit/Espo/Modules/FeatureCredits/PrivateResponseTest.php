<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\ControllerActionHandler;
use Espo\Core\Api\ControllerActionProcessor;
use Espo\Core\Api\MiddlewareProvider;
use Espo\Core\Api\ProcessData;
use Espo\Core\Api\ResponseWrapper;
use Espo\Core\Api\Route;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureCredits\Classes\Api\PrivateResponse;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\MiddlewareDispatcher;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class PrivateResponseTest extends TestCase
{
    public static function controllers(): array
    {
        return [['CreditBalance', 'status'], ['CreditHistory', 'list'], ['CreditGrants', 'list'], ['CreditReservations', 'list'],
            ['CreditRequests', 'list'], ['CreditOperations', 'list'], ['CreditOperationSource', 'read'], ['Credits', 'context'],
            ['CreditExecution', 'execute'], ['CreditDispatch', 'execute']];
    }

    #[DataProvider('controllers')]
    public function testConfiguredMiddlewarePreservesPrivacyAfterRealControllerHandler(string $controller, string $action): void
    {
        $settings = json_decode(file_get_contents(
            'custom/Espo/Modules/FeatureCredits/Resources/metadata/app/api.json'
        ), true, 512, JSON_THROW_ON_ERROR);
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(static fn ($path) =>
            $settings[$path[2]][$path[3]] ?? null);
        $factory = $this->createMock(InjectableFactory::class);
        $factory->method('create')->with(PrivateResponse::class)->willReturn(new PrivateResponse());
        $provider = new MiddlewareProvider($metadata, $factory);
        $this->assertSame([], $provider->getControllerMiddlewareList('Unrelated'));

        $response = new ResponseWrapper(new Response());
        $response->setHeader('Cache-Control', 'private, no-store');
        $response->setHeader('X-Credit-Test', 'preserved');
        $response->writeBody('{"balance":"0.0000"}');
        $processor = $this->createMock(ControllerActionProcessor::class);
        $processor->method('process')->willReturn($response);
        $params = ['controller' => $controller, 'action' => $action];
        $route = new Route('get', '/' . $controller, '/' . $controller, $params, false, null);
        $handler = new ControllerActionHandler($controller, $action,
            new ProcessData($route, '/api/v1', $params), $response, $processor, $this->createMock(Config::class));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/' . $controller . '?tenantId=tenant');

        // Reproduce the production failure: Espo overwrites the controller's header.
        $this->assertStringNotContainsString('private', $handler->handle($request)->getHeaderLine('Cache-Control'));
        $dispatcher = new MiddlewareDispatcher($handler);
        foreach ($provider->getControllerMiddlewareList($controller) as $middleware) {
            $dispatcher->addMiddleware($middleware);
        }
        $result = $dispatcher->handle($request);
        $this->assertSame('private, no-store', $result->getHeaderLine('Cache-Control'));
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('application/json', $result->getHeaderLine('Content-Type'));
        $this->assertSame('preserved', $result->getHeaderLine('X-Credit-Test'));
        $this->assertSame('{"balance":"0.0000"}', (string) $result->getBody());
    }
}
