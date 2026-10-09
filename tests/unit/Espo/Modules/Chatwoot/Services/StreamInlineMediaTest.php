<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Modules\Chatwoot\Tools\Stream\InlineMedia;
use PHPUnit\Framework\TestCase;

class StreamInlineMediaTest extends TestCase
{
    private function path(string $name = '1000159336.jpg'): string
    {
        return '/rails/active_storage/blobs/redirect/eyJfcmFpbHMi==--' . str_repeat('a', 40) . '/' . $name;
    }

    public function testLexicalImageWithoutCrmAttachmentResolvesToConfiguredStorage(): void
    {
        $url = 'https://chat.test' . $this->path();
        $items = InlineMedia::fromPost('<img src="' . $url . '" alt="photo"><p>What is this?</p><img src="' . $url . '">',
            'https://chat.test', 'http://chatwoot-service:3000');
        self::assertCount(1, $items);
        self::assertSame('image/jpeg', $items[0]['type']);
        self::assertSame('1000159336.jpg', $items[0]['name']);
        self::assertNull($items[0]['size']);
        self::assertSame('http://chatwoot-service:3000' . $this->path(), $items[0]['storageUrl']);
        self::assertStringStartsWith('inline-', $items[0]['id']);
    }

    public function testOnlyConfiguredOriginsAndSignedBlobPathsAreAccepted(): void
    {
        foreach ([
            'https://evil.test' . $this->path(), 'http://169.254.169.254' . $this->path(),
            'https://chat.test.evil.test' . $this->path(), 'https://user:secret@chat.test' . $this->path(),
            'https://chat.test:444' . $this->path(), 'https://chat.test/api/v1/accounts/6',
            'https://chat.test/rails/active_storage/blobs/redirect/unsigned/photo.jpg',
            'https://chat.test' . $this->path('%2fprivate.jpg'), 'data:image/png;base64,abc',
        ] as $url) {
            self::assertSame([], InlineMedia::fromPost('<img src="' . $url . '">', 'https://chat.test', 'http://chatwoot-service:3000'), $url);
        }
    }

    public function testAudioAndFileLinksUseTheSameSavedPostBoundary(): void
    {
        $html = '<audio src="https://chat.test' . $this->path('voice.ogg') . '"></audio>' .
            '<a href="https://chat.test' . $this->path('report.pdf') . '">Report</a>';
        self::assertSame(['audio/ogg', 'application/pdf'], array_column(InlineMedia::fromPost($html,
            'https://chat.test', 'http://chatwoot-service:3000'), 'type'));
    }
}
