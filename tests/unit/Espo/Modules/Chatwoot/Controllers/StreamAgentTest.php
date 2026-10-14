<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Controllers\StreamAgent;
use Espo\Modules\Chatwoot\Services\StreamAgent as Service;
use PHPUnit\Framework\TestCase;

class StreamAgentTest extends TestCase
{
    private function request(string $entity): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getRouteParam')->willReturnMap([['id', 'note'], ['membershipId', 'agent']]);
        $request->method('getParsedBody')->willReturn((object) [
            'workflowRunId' => 'run', 'sequence' => 2, 'phase' => 'thinking',
            'activities' => [(object) [
                'id' => 'lookup', 'kind' => 'read', 'entity' => $entity, 'status' => 'completed',
                'startedAt' => '2026-10-14T13:20:00.000Z', 'finishedAt' => '2026-10-14T13:20:01.000Z',
                'arguments' => (object) ['private' => 'not-for-progress'],
            ]],
        ]);
        return $request;
    }

    public function testUserLookupProgressReachesServiceWithoutPrivateToolData(): void
    {
        $service = $this->createMock(Service::class);
        $service->expects($this->once())->method('updateProgress')->with('note', 'agent', 'run',
            $this->callback(function (object $snapshot): bool {
                self::assertSame('User', $snapshot->activities[0]->entity);
                self::assertSame('completed', $snapshot->activities[0]->status);
                self::assertObjectNotHasProperty('arguments', $snapshot->activities[0]);
                return true;
            }))->willReturn((object) ['success' => true]);
        self::assertTrue((new StreamAgent($service))->postActionProgress($this->request('User'))->success);
    }

    public function testUnknownProgressEntitiesRemainRejected(): void
    {
        $service = $this->createMock(Service::class);
        $service->expects($this->never())->method('updateProgress');
        $this->expectException(BadRequest::class);
        (new StreamAgent($service))->postActionProgress($this->request('PrivateEntity'));
    }
}
