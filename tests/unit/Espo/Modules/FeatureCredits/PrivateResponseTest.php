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
            ['CreditWorkspace', 'read'], ['CreditWorkspace', 'conversation'],
            ['CreditExecution', 'execute'], ['CreditDispatch', 'execute'], ['CreditAsaasEvents', 'receive'], ['CreditReversals', 'reverse'],
            ['CreditBillingConfiguration', 'read'], ['CreditBillingConfiguration', 'publish'],
            ['CreditConfiguration', 'read'], ['CreditConfiguration', 'publish'],
            ['CreditBilling', 'context'], ['CreditBilling', 'obligation'], ['CreditBilling', 'country'],
            ['CreditBilling', 'override'], ['CreditBilling', 'accept'], ['CreditBilling', 'cancel'],
            ['CreditBilling', 'customer'], ['CreditBilling', 'charge'], ['CreditBilling', 'recoverCreation'],
            ['CreditCheckout', 'context'], ['CreditCheckout', 'read'], ['CreditCheckout', 'payment'],
            ['CreditSubscriptions', 'cancel'], ['CreditSubscriptions', 'reconcile'],
            ['CreditEnrichmentConfiguration', 'read'], ['CreditEnrichmentConfiguration', 'publish'],
            ['CreditEnrichmentQuotes', 'quote'], ['CreditEnrichmentQuotes', 'confirm'], ['CreditEnrichmentReview', 'read'],
            ['CreditEnrichmentApplication', 'apply'], ['CreditEnrichmentHistory', 'list'], ['CreditEnrichmentFields', 'apply']];
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

    public function testRouteMiddlewareAlsoWrapsErrorsRenderedOutsideControllerMiddleware(): void
    {
        $settings = json_decode(file_get_contents('custom/Espo/Modules/FeatureCredits/Resources/metadata/app/api.json'), true, 32, JSON_THROW_ON_ERROR);
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(static fn ($path) => $settings[$path[2]][$path[3]] ?? null);
        $factory = $this->createMock(InjectableFactory::class);
        $factory->method('create')->with(PrivateResponse::class)->willReturn(new PrivateResponse());
        $provider = new MiddlewareProvider($metadata, $factory);
        $routes = [['post', '/CreditAsaasEvents/:integrationId'], ['post', '/CreditReversals']];
        $routes = [...$routes, ['get', '/CreditConfiguration'], ['post', '/CreditConfiguration']];
        $routes = [...$routes, ['get', '/CreditCheckout/context'], ['get', '/CreditCheckout'], ['post', '/CreditCheckout/payment']];
        foreach (['context', 'obligation'] as $action) $routes[] = ['get', '/CreditBilling/' . $action];
        foreach (['country', 'override', 'accept', 'cancel', 'customer', 'charge', 'recoverCreation'] as $action) $routes[] = ['post', '/CreditBilling/' . $action];
        foreach (['cancel', 'reconcile'] as $action) $routes[] = ['post', '/CreditSubscriptions/' . $action];
        foreach ($routes as [$method, $path]) foreach ([400, 403, 404, 409, 503] as $status) {
            $route = new Route($method, $path, $path, [], true, null);
            $handler = new class ($status) implements \Psr\Http\Server\RequestHandlerInterface {
                public function __construct(private int $status) {}
                public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                    // Espo RouteProcessor converts the controller exception into a fresh error response.
                    return new Response($this->status);
                }
            };
            $dispatcher = new MiddlewareDispatcher($handler);
            $middlewares = $provider->getRouteMiddlewareList($route);
            $this->assertCount(1, $middlewares);
            foreach ($middlewares as $middleware) $dispatcher->addMiddleware($middleware);
            $request = (new ServerRequestFactory())->createServerRequest(strtoupper($method), '/api/v1' . $path);
            $response = $dispatcher->handle($request);
            $this->assertSame($status, $response->getStatusCode());
            $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        }
    }
}
