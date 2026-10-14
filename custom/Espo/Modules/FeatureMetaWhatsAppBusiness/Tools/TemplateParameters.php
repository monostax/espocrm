<?php

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Tools;

use Espo\Core\Exceptions\Error;

class TemplateParameters
{
    public static function mapping(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw new Error('Invalid template parameter mapping.');
        }
        foreach ($value as $number => $expression) {
            if (!preg_match('/^[1-9][0-9]*$/D', (string) $number) || !is_string($expression)) {
                throw new Error('Template mappings require numbered string values.');
            }
        }
        ksort($value, SORT_NUMERIC);
        return $value;
    }

    public static function validate(array $template, mixed $body, mixed $header, ?string $mediaUrl, ?string $mediaType): void
    {
        $body = self::mapping($body);
        $header = self::mapping($header);
        if (($template['parameter_format'] ?? 'POSITIONAL') !== 'POSITIONAL') {
            throw new Error('Named template parameters are not supported by this sender.');
        }
        $required = ['BODY' => [], 'HEADER' => []];
        $media = TemplateDefinition::mediaFormat($template);
        foreach ($template['components'] ?? [] as $component) {
            $type = $component['type'] ?? '';
            if (isset($required[$type]) && isset($component['text'])) {
                $required[$type] = TemplateDefinition::variables($component['text']);
            }
        }
        foreach (['BODY' => $body, 'HEADER' => $header] as $type => $mapping) {
            if (array_map('intval', array_keys($mapping)) !== $required[$type]) {
                throw new Error("Repair the {$type} parameter mapping to match the live template. Header values are never inferred from body values.");
            }
            self::assertResolved($mapping);
        }
        if ($media) {
            if ($header || strtolower($media) !== $mediaType || !$mediaUrl ||
                !filter_var($mediaUrl, FILTER_VALIDATE_URL) ||
                !in_array(strtolower(parse_url($mediaUrl, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true)) {
                throw new Error('A matching media header type and public HTTP(S) URL are required.');
            }
        } elseif ($mediaUrl || $mediaType) {
            throw new Error('Remove the media header configuration for this text template.');
        }
    }

    public static function assertResolved(array $values, bool $rendered = false): void
    {
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '' ||
                ($rendered && (str_contains($value, '{{') || str_contains($value, '}}')))) {
                throw new Error('A required template parameter could not be resolved; message was not sent.');
            }
        }
    }

    public static function processed(array $body, array $header, ?string $url, ?string $type): \stdClass
    {
        $body = self::mapping($body);
        $header = self::mapping($header);
        self::assertResolved($body, true);
        self::assertResolved($header, true);
        if ($header && ($url || $type)) {
            throw new Error('Text and media header parameters cannot be mixed.');
        }
        $result = new \stdClass();
        if ($body) {
            $result->body = (object) $body;
        }
        if ($header) {
            $result->header = (object) $header;
        } elseif ($url && $type) {
            $result->header = ['media_url' => $url, 'media_type' => $type];
        }
        return $result;
    }

    /** Canonical send-relevant content, retaining component/button ordering. */
    public static function signature(array $template): array
    {
        $clean = function (mixed $value) use (&$clean): mixed {
            if (!is_array($value)) {
                return $value;
            }
            unset($value['example']);
            foreach ($value as $key => $item) {
                $value[$key] = $clean($item);
            }
            if (!array_is_list($value)) {
                ksort($value);
            }
            return $value;
        };
        return [
            'name' => $template['name'] ?? null, 'language' => $template['language'] ?? null,
            'category' => strtoupper($template['category'] ?? ''),
            'status' => strtoupper($template['status'] ?? ''),
            'parameter_format' => $template['parameter_format'] ?? 'POSITIONAL',
            'components' => $clean($template['components'] ?? []),
        ];
    }
}
