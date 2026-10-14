<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\{Conflict, Forbidden};
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Classes\Api\{CustomerResponse, PrivateRecords};
use Espo\Modules\FeatureCredits\Classes\Metadata\CustomerBoundaries;
use Espo\Modules\FeatureCredits\Classes\Record\PrivateOutput;
use Espo\Modules\FeatureAiUsage\Classes\Record\CustomerOutput;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CustomerBoundariesTest extends TestCase
{
    public function testAllFinancialStorageIsPrivateIncludingFutureEntitiesAndExports(): void
    {
        $data = json_decode('{"scopes":{"EnrichmentQuote":{"module":"FeatureCredits","entity":true},
            "FutureReceipt":{"module":"FeatureCredits","entity":true},"CreditWorkspace":{"module":"FeatureCredits","entity":false},
            "Contact":{"module":"Crm","entity":true}},"recordDefs":{}}');
        (new CustomerBoundaries())->build($data);
        foreach (['EnrichmentQuote', 'FutureReceipt'] as $type) {
            self::assertTrue($data->recordDefs->$type->exportDisabled);
            self::assertContains(PrivateOutput::class, $data->recordDefs->$type->outputFilterClassNameList);
            self::assertSame([PrivateRecords::class], $data->app->api->controllerMiddlewareClassNameListMap->$type);
            self::assertContains(['entityDefs', $type], $data->app->metadata->frontendHiddenPathList);
        }
        self::assertObjectNotHasProperty('Contact', $data->recordDefs);
        foreach (CustomerBoundaries::CONTROLLERS as $controller) {
            self::assertContains(CustomerResponse::class, $data->app->api->controllerMiddlewareClassNameListMap->$controller);
        }
    }

    public function testGenericQueriesAreDeniedBeforeAnyRecordOrCountIsRead(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $this->expectException(Forbidden::class);
        (new PrivateRecords())->process($this->createMock(ServerRequestInterface::class), $handler);
    }

    public function testErrorsKeepStatusButNotProviderMessagesHeadersOrRawBodies(): void
    {
        foreach ([true, false] as $throw) {
            $handler = $this->createMock(RequestHandlerInterface::class);
            if ($throw) {
                $handler->method('handle')->willThrowException(Conflict::createWithBody('private-supplier failed', '{"provider":"private-supplier"}'));
            } else {
                $response = (new Response(409))->withHeader('X-Status-Reason', 'private-supplier failed');
                $response->getBody()->write('{"provider":"private-supplier"}');
                $handler->method('handle')->willReturn($response);
            }
            $log = $this->createMock(Log::class);
            $log->expects(self::once())->method($throw ? 'error' : 'warning');
            $actual = (new CustomerResponse($log))->process($this->createMock(ServerRequestInterface::class), $handler);
            self::assertSame(409, $actual->getStatusCode());
            self::assertSame('request_conflict', $actual->getHeaderLine('X-Status-Reason'));
            self::assertSame(['error' => 'request_conflict'], json_decode((string) $actual->getBody(), true));
            self::assertSame('private, no-store', $actual->getHeaderLine('Cache-Control'));
        }
    }

    public function testGenericAiRecordsRemovePrivateTelemetryForEveryRole(): void
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getValueMap')->willReturn((object) ['id' => 'run', 'model' => 'private',
            'modelUsage' => ['private'], 'hatchetEventId' => 'private', 'futureInternalField' => 'private']);
        $fields = [];
        $entity->expects(self::exactly(4))->method('clear')->willReturnCallback(function ($field) use (&$fields): void { $fields[] = $field; });
        (new CustomerOutput())->filter($entity);
        self::assertSame(['model', 'modelUsage', 'hatchetEventId', 'futureInternalField'], $fields);
    }
}
