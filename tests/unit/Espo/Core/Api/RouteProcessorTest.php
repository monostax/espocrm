<?php

namespace tests\unit\Espo\Core\Api;

use Espo\Core\Api\{Auth, AuthBuilder, AuthBuilderFactory, AuthResult, ControllerActionProcessor,
    ErrorOutput, MiddlewareProvider, ProcessData, Request, Response, Route, RouteProcessor};
use Espo\Core\ApplicationUser;
use Espo\Core\Authentication\AuthenticationFactory;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\{Config, Log};
use GuzzleHttp\Psr7\{LimitStream, PumpStream, Response as PsrResponse, ServerRequest, Utils};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\ResponseEmitter;

class RouteProcessorTest extends TestCase
{
    private RouteProcessor $processor;
    private ControllerActionProcessor $controller;
    private ErrorOutput $errors;
    private bool $authenticated = true;
    private int $bufferLevel;

    protected function setUp(): void
    {
        $this->bufferLevel = ob_get_level();
        $auth = $this->createMock(Auth::class);
        $auth->method('process')->willReturnCallback(function (Request $request, Response $response) {
            if (!$this->authenticated) {
                $response->setStatus(401);
                return AuthResult::createNotResolved();
            }

            return AuthResult::createResolved();
        });
        $builder = $this->createMock(AuthBuilder::class);
        $builder->method('setAuthentication')->willReturnSelf();
        $builder->expects($this->once())->method('setAuthRequired')->with(true)->willReturnSelf();
        $builder->method('build')->willReturn($auth);
        $builders = $this->createStub(AuthBuilderFactory::class);
        $builders->method('create')->willReturn($builder);
        $this->controller = $this->createMock(ControllerActionProcessor::class);
        $this->errors = $this->createMock(ErrorOutput::class);
        $this->processor = new RouteProcessor(
            $this->createStub(AuthenticationFactory::class),
            $builders,
            $this->errors,
            $this->createStub(Config::class),
            $this->createStub(Log::class),
            $this->createStub(ApplicationUser::class),
            $this->controller,
            $this->createStub(MiddlewareProvider::class),
            $this->createStub(InjectableFactory::class),
        );
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->bufferLevel) {
            ob_end_clean();
        }
    }

    private function process(): ResponseInterface
    {
        $params = ['controller' => 'MeetingGoogleImport', 'action' => 'playback'];

        return $this->processor->process(
            new ProcessData(new Route('GET', '/recording', '/recording', $params, false, null), '', $params),
            new ServerRequest('GET', '/recording'),
            new PsrResponse(),
        );
    }

    private function handle(callable $callback): void
    {
        $this->controller->expects($this->once())->method('process')->willReturnCallback(
            function (string $controller, string $action, Request $request, Response $response) use ($callback) {
                echo 'accidental controller output';
                $callback($response);

                return $response;
            }
        );
    }

    public function testJsonResponsePreservesCallerBufferAndDiscardsControllerOutput(): void
    {
        ob_start();
        echo 'caller output';
        $level = ob_get_level();
        $this->handle(fn (Response $response) => $response->writeBody('{"ok":true}'));

        $response = $this->process();

        $this->assertSame($level, ob_get_level());
        $this->assertSame('caller output', ob_get_clean());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function testNonzeroRangeSurvivesEmitterRewind(): void
    {
        $stream = new LimitStream(Utils::streamFor('0123456789'), 4, 3);
        $this->handle(function (Response $response) use ($stream) {
            $response->setStatus(206)->setHeader('Content-Length', '4')
                ->setHeader('Content-Range', 'bytes 3-6/10')->setBody($stream);
        });
        $response = $this->process();

        $this->assertSame($this->bufferLevel, ob_get_level());
        $this->assertSame(0, $stream->tell());
        ob_start();
        (new ResponseEmitter())->emit($response);
        $this->assertSame('3456', ob_get_clean());
        $this->assertSame('bytes 3-6/10', $response->getHeaderLine('Content-Range'));
    }

    public function testLargeResponseEmitsIncrementallyWithBoundedMemory(): void
    {
        $size = 600536040;
        $remaining = $size;
        $stream = new PumpStream(function (int $length) use (&$remaining) {
            if (!$remaining) {
                return false;
            }

            $length = min($length, $remaining);
            $remaining -= $length;
            return str_repeat('x', $length);
        });
        $this->handle(fn (Response $response) => $response->setStatus(206)
            ->setHeader('Content-Length', (string) $size)->setBody($stream));
        $response = $this->process();
        $this->assertSame($this->bufferLevel, ob_get_level());
        $this->assertSame($size, $remaining);

        $delivered = 0;
        ob_start(function (string $chunk) use (&$delivered): string {
            $delivered += strlen($chunk);
            return '';
        }, 8192);
        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        (new ResponseEmitter())->emit($response);
        $this->assertGreaterThan(0, $delivered);
        ob_end_flush();
        $this->assertSame($size, $delivered);
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    public function testControllerExceptionClosesBufferBeforeErrorResponse(): void
    {
        $this->handle(function () {
            throw new \RuntimeException('test failure');
        });
        $this->errors->expects($this->once())->method('process')->willReturnCallback(
            function (Request $request, Response $response) {
                $this->assertSame($this->bufferLevel, ob_get_level());
                $response->setStatus(500)->writeBody('{"error":"test"}');
            }
        );
        $response = $this->process();

        $this->assertSame($this->bufferLevel, ob_get_level());
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('{"error":"test"}', (string) $response->getBody());
    }

    public function testAuthenticationDenialDoesNotRunControllerOrOpenBuffer(): void
    {
        $this->authenticated = false;
        $this->controller->expects($this->never())->method('process');
        $response = $this->process();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame($this->bufferLevel, ob_get_level());
    }
}
