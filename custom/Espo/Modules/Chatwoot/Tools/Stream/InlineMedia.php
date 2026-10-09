<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

/** Lexical direct uploads live in Chatwoot, not necessarily in Note.attachments. */
class InlineMedia
{
    public static function fromPost(string $html, string $frontendUrl, string $backendUrl): array
    {
        $frontend = parse_url($frontendUrl);
        $backend = parse_url($backendUrl);
        if (!self::isHttp($frontend) || !self::isHttp($backend) || $html === '') return [];
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
            'pdf' => 'application/pdf', 'txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv', 'json' => 'application/json',
            'aac' => 'audio/aac', 'flac' => 'audio/flac', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav',
            'ogg' => 'audio/ogg', 'webm' => 'audio/webm'];
        $items = [];
        foreach ((new \DOMXPath($document))->query('//img[@src] | //audio[@src] | //source[@src] | //a[@href]') as $node) {
            if (!$node instanceof \DOMElement) continue;
            $url = parse_url($node->getAttribute($node->nodeName === 'a' ? 'href' : 'src'));
            if (!self::isHttp($url)) continue;
            $origin = null;
            foreach ([$frontend, $backend] as $allowed) {
                if (self::origin($url) === self::origin($allowed)) { $origin = $allowed; break; }
            }
            if ($origin === null || isset($url['fragment'])) continue;
            $prefix = rtrim($origin['path'] ?? '', '/') . '/rails/active_storage/blobs/';
            $path = $url['path'] ?? '';
            if (!str_starts_with($path, $prefix)) continue;
            $suffix = substr($path, strlen($prefix));
            // Only signed original-blob routes, never arbitrary application paths.
            if (!preg_match('~^(redirect|proxy)/([A-Za-z0-9_=%+\-]+--[a-f0-9]{40,64})/([^/]+)$~D', $suffix, $match)) continue;
            $name = rawurldecode($match[3]);
            if (str_contains($name, '/') || str_contains($name, '\\') || preg_match('/[\\x00-\\x1f]/', $name)) continue;
            $type = $types[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
            if ($type === null) continue;
            $storageUrl = rtrim($backendUrl, '/') . '/rails/active_storage/blobs/' . $suffix;
            $id = 'inline-' . substr(hash('sha256', $storageUrl), 0, 40);
            $items[$id] = ['id' => $id, 'name' => $name, 'type' => $type, 'size' => null, 'storageUrl' => $storageUrl];
            if (count($items) >= 20) break;
        }
        return array_values($items);
    }

    private static function isHttp(array|false $url): bool
    {
        return is_array($url) && in_array($url['scheme'] ?? '', ['http', 'https'], true) &&
            !empty($url['host']) && !isset($url['user']) && !isset($url['pass']) && !isset($url['query']);
    }

    private static function origin(array $url): string
    {
        return strtolower($url['scheme'] . '://' . $url['host']) . ':' . ($url['port'] ?? ($url['scheme'] === 'https' ? 443 : 80));
    }
}
