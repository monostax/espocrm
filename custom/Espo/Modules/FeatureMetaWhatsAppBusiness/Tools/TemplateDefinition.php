<?php

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Tools;

use Espo\Core\Exceptions\BadRequest;

/** The supported v1 subset. Unknown content is refused, never stripped. */
class TemplateDefinition
{
    public const LANGUAGES = [
        'af', 'sq', 'ar', 'az', 'bn', 'bg', 'ca', 'zh_CN', 'zh_HK', 'zh_TW', 'hr', 'cs', 'da',
        'nl', 'en', 'en_GB', 'en_US', 'et', 'fil', 'fi', 'fr', 'fr_CA', 'ka', 'de', 'el', 'gu',
        'ha', 'he', 'hi', 'hu', 'id', 'ga', 'it', 'ja', 'kn', 'kk', 'rw_RW', 'ko', 'ky_KG',
        'lo', 'lv', 'lt', 'mk', 'ms', 'ml', 'mr', 'nb', 'ps_AF', 'fa', 'pl', 'pt_BR', 'pt_PT',
        'pa', 'ro', 'ru', 'sr', 'si_LK', 'sk', 'sl', 'es', 'es_AR', 'es_ES', 'es_MX', 'sw',
        'sv', 'ta', 'te', 'th', 'tr', 'uk', 'ur', 'uz', 'vi', 'zu',
    ];

    public function validate(array $definition, bool $requireExamples = true): array
    {
        $this->keys($definition, ['name', 'language', 'category', 'parameter_format', 'components']);
        if (!is_string($definition['name'] ?? null) ||
            !preg_match('/^[a-z0-9_]{1,512}$/D', $definition['name'])) {
            throw new BadRequest('Template name must contain 1–512 lowercase letters, digits or underscores.');
        }
        if (!in_array($definition['language'] ?? null, self::LANGUAGES, true)) {
            throw new BadRequest('Unsupported template language.');
        }
        if (!in_array($definition['category'] ?? null, ['MARKETING', 'UTILITY'], true) ||
            ($definition['parameter_format'] ?? 'POSITIONAL') !== 'POSITIONAL') {
            throw new BadRequest('Only marketing/utility templates with positional variables are supported.');
        }
        $components = $definition['components'] ?? null;
        if (!is_array($components) || !array_is_list($components) || !$components) {
            throw new BadRequest('A body component is required.');
        }
        $seen = [];
        foreach ($components as $component) {
            if (!is_array($component)) {
                throw new BadRequest('Invalid template component.');
            }
            $type = $component['type'] ?? '';
            if (!in_array($type, ['HEADER', 'BODY', 'FOOTER', 'BUTTONS'], true) || isset($seen[$type])) {
                throw new BadRequest('Unsupported or duplicate template component.');
            }
            $seen[$type] = true;
            if ($type === 'BUTTONS') {
                $this->keys($component, ['type', 'buttons']);
                $this->buttons($component['buttons'] ?? null);
                continue;
            }
            $this->keys($component, $type === 'HEADER'
                ? ['type', 'format', 'text', 'example'] : ['type', 'text', 'example']);
            if ($type === 'HEADER' && ($component['format'] ?? '') !== 'TEXT') {
                if (!in_array($component['format'] ?? '', ['IMAGE', 'VIDEO', 'DOCUMENT'], true) ||
                    isset($component['text'])) {
                    throw new BadRequest('Unsupported header format.');
                }
                $this->keys($component['example'] ?? [], ['header_handle']);
                continue; // A fresh, validated upload supplies the handle; clients cannot reuse it.
            }
            $text = $component['text'] ?? null;
            $this->text($text, $type === 'BODY' ? 1024 : 60);
            $variables = self::variables($text);
            if (($type === 'HEADER' && count($variables) > 1) || ($type === 'FOOTER' && $variables)) {
                throw new BadRequest('A text header supports one variable; a footer supports none.');
            }
            $exampleKey = $type === 'BODY' ? 'body_text' : 'header_text';
            $example = $component['example'] ?? [];
            $this->keys($example, $type === 'FOOTER' ? [] : [$exampleKey]);
            if ($requireExamples && $variables) {
                $values = $type === 'BODY' ? ($example[$exampleKey][0] ?? null) : ($example[$exampleKey] ?? null);
                if (!is_array($values) || !array_is_list($values) || count($values) !== count($variables)) {
                    throw new BadRequest('Provide one example for each positional variable.');
                }
                foreach ($values as $value) {
                    $this->text($value, $type === 'BODY' ? 1024 : 60);
                }
            }
        }
        if (!isset($seen['BODY'])) {
            throw new BadRequest('A body component is required.');
        }
        $definition['parameter_format'] = 'POSITIONAL';
        return $definition;
    }

    public function supported(array $template): bool
    {
        // Top-level lifecycle/quality fields are read-only metadata, not editable content.
        // TTL/authentication settings would be lost by a complete-definition edit.
        if (isset($template['message_send_ttl_seconds']) || isset($template['library_template_name'])) {
            return false;
        }
        try {
            $this->validate(array_intersect_key($template, array_flip([
                'name', 'language', 'category', 'parameter_format', 'components',
            ])), false);
            return true;
        } catch (BadRequest) {
            return false;
        }
    }

    public static function variables(string $text): array
    {
        preg_match_all('/\{\{([1-9][0-9]*)\}\}/', $text, $matches);
        $rest = preg_replace('/\{\{([1-9][0-9]*)\}\}/', '', $text);
        if (str_contains($rest, '{{') || str_contains($rest, '}}')) {
            throw new BadRequest('Only positional variables such as {{1}} are supported.');
        }
        $numbers = array_values(array_unique(array_map('intval', $matches[1])));
        sort($numbers, SORT_NUMERIC);
        if ($numbers && $numbers !== range(1, count($numbers))) {
            throw new BadRequest('Variables must be contiguous from 1 within each component.');
        }
        return $numbers;
    }

    public static function mediaFormat(array $definition): ?string
    {
        foreach ($definition['components'] ?? [] as $component) {
            if (($component['type'] ?? '') === 'HEADER' &&
                in_array($component['format'] ?? '', ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                return $component['format'];
            }
        }
        return null;
    }

    private function buttons(mixed $buttons): void
    {
        if (!is_array($buttons) || !array_is_list($buttons) || count($buttons) < 1 || count($buttons) > 10) {
            throw new BadRequest('Provide 1–10 buttons.');
        }
        $counts = ['URL' => 0, 'PHONE_NUMBER' => 0];
        $groups = [];
        foreach ($buttons as $button) {
            if (!is_array($button)) {
                throw new BadRequest('Invalid button.');
            }
            $type = $button['type'] ?? '';
            if (!in_array($type, ['QUICK_REPLY', 'URL', 'PHONE_NUMBER'], true)) {
                throw new BadRequest('Unsupported button type.');
            }
            $this->keys($button, match ($type) {
                'URL' => ['type', 'text', 'url'],
                'PHONE_NUMBER' => ['type', 'text', 'phone_number'],
                default => ['type', 'text'],
            });
            $this->text($button['text'] ?? null, 25);
            if (self::variables($button['text'])) {
                throw new BadRequest('Button labels cannot contain variables.');
            }
            $group = $type === 'QUICK_REPLY' ? 'quick' : 'cta';
            if (end($groups) !== $group) {
                $groups[] = $group;
            }
            if ($type === 'URL') {
                $counts['URL']++;
                $url = $button['url'] ?? null;
                $this->text($url, 2000);
                if (!filter_var($url, FILTER_VALIDATE_URL) ||
                    !in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true) ||
                    self::variables($url)) {
                    throw new BadRequest('Buttons require a static HTTP(S) URL.');
                }
            }
            if ($type === 'PHONE_NUMBER') {
                $counts['PHONE_NUMBER']++;
                if (!is_string($button['phone_number'] ?? null) ||
                    !preg_match('/^\+[1-9][0-9]{6,14}$/D', $button['phone_number'])) {
                    throw new BadRequest('Phone buttons require an international phone number.');
                }
            }
        }
        if ($counts['URL'] > 2 || $counts['PHONE_NUMBER'] > 1 || count($groups) > 2) {
            throw new BadRequest('Use at most two URL buttons and one phone button; group quick replies together.');
        }
    }

    private function text(mixed $text, int $limit): void
    {
        if (!is_string($text) || trim($text) === '' || mb_strlen($text) > $limit) {
            throw new BadRequest("Text must contain 1–{$limit} characters.");
        }
    }

    private function keys(mixed $value, array $allowed): void
    {
        if (!is_array($value) || array_diff(array_keys($value), $allowed)) {
            throw new BadRequest('Unsupported template content; use Meta Manager for this format.');
        }
    }
}
