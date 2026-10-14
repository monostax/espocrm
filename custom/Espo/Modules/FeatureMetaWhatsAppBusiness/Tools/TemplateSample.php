<?php

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Tools;

use Espo\Core\Exceptions\BadRequest;

/** Request-owned PHP upload. No attachment or reusable handle is persisted. */
class TemplateSample
{
    public function validate(string $path, string $format): array
    {
        $size = is_file($path) ? filesize($path) : 0;
        $limit = $format === 'IMAGE' ? 5000000 : 16000000;
        if (!$size || $size > $limit) {
            throw new BadRequest("Sample must be nonempty and no larger than {$limit} bytes.");
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowed = match ($format) {
            'IMAGE' => ['image/jpeg', 'image/png'],
            'VIDEO' => ['video/mp4'],
            'DOCUMENT' => ['application/pdf'],
            default => [],
        };
        if (!in_array($mime, $allowed, true) ||
            ($format === 'IMAGE' && @getimagesize($path) === false)) {
            throw new BadRequest('Sample contents must be JPEG/PNG, MP4 or PDF matching the header format.');
        }
        return ['path' => $path, 'size' => $size, 'mime' => $mime];
    }
}
