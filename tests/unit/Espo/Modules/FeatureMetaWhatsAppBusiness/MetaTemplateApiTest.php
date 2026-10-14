<?php

namespace tests\unit\Espo\Modules\FeatureMetaWhatsAppBusiness;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\MetaGraphApiClient;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateDeadline;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateFailure;
use PHPUnit\Framework\TestCase;

class MetaTemplateApiTest extends TestCase
{
    private function client(array $responses, ?Config $config = null): MetaGraphApiClient
    {
        return new class($this->createStub(Log::class), $responses, $config) extends MetaGraphApiClient {
            public array $calls = [];
            public function __construct(Log $log, public array $responses, ?Config $config) { parent::__construct($log, $config); }
            protected function templateHttpRequest(string $url, string $method, array $headers, ?string $body, int $timeout): array
            {
                $this->calls[] = compact('url', 'method', 'headers', 'body', 'timeout');
                return array_shift($this->responses) ?? [false, 0];
            }
        };
    }

    public function testAllPagesDeduplicateAndFindExactLanguageWithoutFollowingNextUrl(): void
    {
        $en = ['id' => '1', 'name' => 'same', 'language' => 'en_US'];
        $pt = ['id' => '2', 'name' => 'same', 'language' => 'pt_BR'];
        $responses = [[json_encode(['data' => [$en], 'paging' => ['next' => 'https://evil.test/token', 'cursors' => ['after' => 'a&b']]]), 200],
            [json_encode(['data' => [$en, $pt]]), 200]];
        $client = $this->client($responses);
        $this->assertSame([$en, $pt], $client->getMessageTemplates('secret', '123'));
        $this->assertStringStartsWith('https://graph.facebook.com/v23.0/123/message_templates?', $client->calls[1]['url']);
        $this->assertStringContainsString('after=a%26b', $client->calls[1]['url']);
        $this->assertStringContainsString('limit=100', $client->calls[1]['url']);
        $this->assertSame($pt, $this->client($responses)->getExactTemplate('secret', '123', 'same', 'pt_BR'));
    }

    public function testRepeatedCursorIsAnErrorInsteadOfPartialSuccess(): void
    {
        $page = [json_encode(['data' => [], 'paging' => ['next' => 'ignored', 'cursors' => ['after' => 'repeat']]]), 200];
        $client = $this->client([$page, $page]);
        $this->expectException(TemplateFailure::class);
        $client->getMessageTemplates('secret', '123');
    }

    public function testPageLimitFailsExplicitly(): void
    {
        $pages = [];
        for ($i = 0; $i < 100; $i++) {
            $pages[] = [json_encode(['data' => [], 'paging' => ['next' => 'ignored', 'cursors' => ['after' => (string) $i]]]), 200];
        }
        $client = $this->client($pages);
        try {
            $client->getMessageTemplates('secret', '123');
            $this->fail('Expected incomplete catalog error');
        } catch (TemplateFailure $e) {
            $this->assertSame('pagination', $e->kind);
            $this->assertCount(100, $client->calls);
        }
    }

    public function testSingleLanguageDeletionAlwaysUsesIdAndName(): void
    {
        $client = $this->client([['{"success":true}', 200]]);
        $client->deleteTemplate('secret', '123', '456', 'same_name', new TemplateDeadline());
        $this->assertSame('DELETE', $client->calls[0]['method']);
        parse_str(parse_url($client->calls[0]['url'], PHP_URL_QUERY), $query);
        $this->assertSame(['hsm_id' => '456', 'name' => 'same_name'], $query);
    }

    /** @dataProvider failures */
    public function testFailuresAreSafeAndWritesAreNeverRetried(mixed $raw, int $status, string $kind): void
    {
        $client = $this->client([[$raw, $status]]);
        try {
            $client->createTemplate('secret', '123', ['body' => 'sensitive'], new TemplateDeadline());
            $this->fail('Expected error');
        } catch (TemplateFailure $e) {
            $this->assertSame($kind, $e->kind);
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertCount(1, $client->calls);
        }
    }

    public static function failures(): array
    {
        return [[false, 0, 'uncertain'], ['not json', 200, 'uncertain'], ['{}', 200, 'uncertain'],
            ['{"error":{"code":190,"message":"secret"}}', 400, 'access'],
            ['{"error":{"code":4,"message":"secret"}}', 400, 'rateLimit'],
            ['{"error":{"code":100,"message":"secret"}}', 400, 'validation'],
            ['{"error":{"message":"secret"}}', 409, 'conflict'],
            ['{"error":{"message":"secret"}}', 500, 'uncertain']];
    }

    public function testUploadUsesLinkedAppAndBinaryBudget(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'template-upload-');
        try {
            file_put_contents($path, 'sample-bytes');
            $client = $this->client([['{"id":"upload:session?sig=abc"}', 200], ['{"h":"sample-handle"}', 200]]);
            $this->assertSame('sample-handle', $client->uploadTemplateSample('secret', '999',
                ['path' => $path, 'size' => 12, 'mime' => 'video/mp4'], new TemplateDeadline()));
            $this->assertStringContainsString('/v23.0/999/uploads?', $client->calls[0]['url']);
            $this->assertStringContainsString('/upload:session?sig=abc', $client->calls[1]['url']);
            $this->assertSame(60000, $client->calls[1]['timeout']);
            $this->assertContains('file_offset: 0', $client->calls[1]['headers']);
            $this->assertSame('sample-bytes', $client->calls[1]['body']);
        } finally { unlink($path); }
    }

    public function testExpiredBudgetNeverStartsARequest(): void
    {
        $client = $this->client([]);
        try {
            $client->createTemplate('secret', '123', [], new TemplateDeadline(0));
            $this->fail('Expected deadline');
        } catch (TemplateFailure $e) {
            $this->assertSame('deadline', $e->kind);
            $this->assertSame([], $client->calls);
        }
    }
}
