<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureLiveRecords;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\UpdateParamsFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpdateParamsFetcherTest extends TestCase
{
    public static function invalidVersions(): array
    {
        return array_map(fn ($value) => [$value], [
            '', '-1', '1.5', '1oops', '1e2', ' 1', '+1', '01',
            '999999999999999999999999999999999999',
        ]);
    }

    #[DataProvider('invalidVersions')]
    public function testInvalidVersionIsRejected(string $value): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getParsedBody')->willReturn((object) []);
        $request->method('getHeader')->with('X-Version-Number')->willReturn($value);
        $this->expectException(BadRequest::class);
        (new UpdateParamsFetcher())->fetch($request);
    }
}
