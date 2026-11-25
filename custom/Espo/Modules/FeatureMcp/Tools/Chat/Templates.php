<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMcp\Tools\Chat;

/**
 * WhatsApp templates (Meta's, as the inbox lists them in `message_templates`): which are approved, what each needs and
 * the `template_params` Chat sends them with (Chatwoot Whatsapp::TemplateProcessorService: `processed_params` body,
 * header and buttons), plus the text shown in the conversation, as the Chat composer sends it.
 */
class Templates
{
    /** @param array<string, mixed> $inbox @return list<array<string, mixed>> */
    public static function approved(array $inbox): array
    {
        $list = [];

        foreach (is_array($inbox['message_templates'] ?? null) ? $inbox['message_templates'] : [] as $template) {
            if (is_array($template) && strtoupper((string) ($template['status'] ?? '')) === 'APPROVED') {
                $list[] = $template;
            }
        }

        return $list;
    }

    /** @param array<string, mixed> $template @return array<string, mixed> */
    public static function describe(array $template): array
    {
        $header = self::component($template, 'HEADER');
        $format = strtoupper((string) ($header['format'] ?? 'TEXT'));

        return array_filter([
            'name' => $template['name'] ?? null,
            'language' => $template['language'] ?? null,
            'category' => $template['category'] ?? null,
            'body' => self::component($template, 'BODY')['text'] ?? null,
            'bodyParameters' => self::keys((string) (self::component($template, 'BODY')['text'] ?? '')) ?: null,
            'header' => $header ? array_filter([
                'format' => $format,
                'text' => $header['text'] ?? null,
                'needs' => $format === 'TEXT'
                    ? (self::keys((string) ($header['text'] ?? '')) ?: null)
                    : ['mediaUrl'],
            ]) : null,
            'buttons' => array_map(fn (array $button, int $index) => array_filter([
                'index' => $index,
                'type' => $button['type'] ?? null,
                'text' => $button['text'] ?? null,
                'needs' => self::buttonNeeds($button),
            ], fn ($it) => $it !== null), self::buttons($template), array_keys(self::buttons($template))) ?: null,
        ], fn ($it) => $it !== null);
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $input {body?: {key: value}, header?: {key: value}|{mediaUrl, mediaName?}, buttons?: list<?string>}
     * @return array{ok: true, content: string, params: array<string, mixed>}|array{ok: false, problem: string, explanation: string}
     */
    public static function build(array $template, array $input): array
    {
        $bodyText = (string) (self::component($template, 'BODY')['text'] ?? '');
        $body = [];

        foreach (self::keys($bodyText) as $key) {
            $value = trim((string) ($input['body'][$key] ?? ''));

            if ($value === '') {
                return self::problem('missing_parameter', "The template needs body parameter \"$key\".");
            }

            $body[$key] = $value;
        }

        $processed = ['body' => (object) $body];
        $header = self::component($template, 'HEADER');

        if ($header) {
            $format = strtoupper((string) ($header['format'] ?? 'TEXT'));

            if ($format === 'TEXT') {
                $values = [];

                foreach (self::keys((string) ($header['text'] ?? '')) as $key) {
                    $value = trim((string) ($input['header'][$key] ?? ''));

                    if ($value === '') {
                        return self::problem('missing_parameter', "The template needs header parameter \"$key\".");
                    }

                    $values[$key] = $value;
                }

                if ($values) {
                    $processed['header'] = (object) $values;
                }
            } else {
                $url = trim((string) ($input['header']['mediaUrl'] ?? ''));

                if (!preg_match('#^https://#', $url)) {
                    return self::problem('missing_parameter', 'The template header needs mediaUrl, an https link to the ' . strtolower($format) . '.');
                }

                $processed['header'] = (object) array_filter([
                    'media_url' => $url,
                    'media_type' => strtolower($format),
                    'media_name' => $input['header']['mediaName'] ?? null,
                ]);
            }
        }

        $buttons = [];

        foreach (self::buttons($template) as $index => $button) {
            $needs = self::buttonNeeds($button);
            $value = trim((string) ($input['buttons'][$index] ?? ''));

            if ($needs && $value === '') {
                return self::problem('missing_parameter', "Button $index needs a $needs.");
            }

            $buttons[] = $needs ? ['type' => $needs === 'code' ? 'copy_code' : 'url', 'parameter' => $value] : null;
        }

        if (array_filter($buttons)) {
            $processed['buttons'] = $buttons;
        }

        return [
            'ok' => true,
            'content' => self::fill($bodyText, $body),
            'params' => [
                'name' => $template['name'] ?? null,
                'category' => $template['category'] ?? null,
                'language' => $template['language'] ?? null,
                'processed_params' => $processed,
            ],
        ];
    }

    /** Placeholder names in order: {{1}}, {{2}} or {{customer_name}}. @return string[] */
    private static function keys(string $text): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @param array<string, string> $values */
    private static function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
            fn ($match) => $values[$match[1]] ?? $match[0],
            $text
        );
    }

    /** @param array<string, mixed> $button */
    private static function buttonNeeds(array $button): ?string
    {
        $type = strtoupper((string) ($button['type'] ?? ''));

        return match (true) {
            $type === 'COPY_CODE' => 'code',
            $type === 'URL' && str_contains((string) ($button['url'] ?? ''), '{{') => 'url',
            default => null,
        };
    }

    /** @param array<string, mixed> $template @return list<array<string, mixed>> */
    private static function buttons(array $template): array
    {
        $buttons = self::component($template, 'BUTTONS')['buttons'] ?? [];

        return is_array($buttons) ? array_values(array_filter($buttons, 'is_array')) : [];
    }

    /** @param array<string, mixed> $template @return ?array<string, mixed> */
    private static function component(array $template, string $type): ?array
    {
        foreach (is_array($template['components'] ?? null) ? $template['components'] : [] as $component) {
            if (is_array($component) && strtoupper((string) ($component['type'] ?? '')) === $type) {
                return $component;
            }
        }

        return null;
    }

    /** @return array{ok: false, problem: string, explanation: string} */
    private static function problem(string $problem, string $explanation): array
    {
        return ['ok' => false, 'problem' => $problem, 'explanation' => $explanation];
    }
}
