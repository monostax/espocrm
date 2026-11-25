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

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Chat messages as a plain transcript, as the AI Session reads them (backend `code-mode/chat-reader.ts` and
 * `$stream-deal-references.ts`: chatSpeaker, chatAttachmentLabels, formatChatTranscript): who spoke, newest messages
 * within a budget shown oldest first, long messages cut with a way to read on, voice messages as their transcript.
 * Keep the two in step so answers read the same in the AI Session and in an AI app.
 */
class Transcript
{
    public const int CHARS = 12000;
    public const int MESSAGE_CHARS = 2500;
    private const int SNIPPET_BEFORE = 120;
    private const int SNIPPET_AFTER = 300;

    /** @param array<string, mixed> $message */
    public static function speaker(array $message): ?string
    {
        $type = $message['message_type'] ?? null;

        if ($type === 2) {
            return null;
        }

        $name = self::oneLine($message['sender']['name'] ?? null, 60);

        return match (true) {
            ($message['private'] ?? false) === true => 'internal note' . ($name !== '' ? " by $name" : ''),
            $type === 0 => 'customer',
            $type === 3 => 'team template' . ($name !== '' ? " ($name)" : ''),
            default => $name !== '' ? "team ($name)" : 'team (sent from the phone)',
        };
    }

    /** @param array<string, mixed> $message */
    public static function text(array $message): string
    {
        $body = is_string($message['content'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $message['content'])) : '';
        $deleted = (self::attributes($message)['deleted'] ?? false) === true ? '[Mensagem excluída]' : '';
        $labels = [];

        foreach (is_array($message['attachments'] ?? null) ? $message['attachments'] : [] as $file) {
            $kind = self::oneLine($file['file_type'] ?? null, 20) ?: 'file';
            $transcript = self::oneLine($file['transcribed_text'] ?? null, self::MESSAGE_CHARS);

            if ($transcript !== '') {
                $labels[] = "[voice message: $transcript]";

                continue;
            }

            $name = self::oneLine($file['fallback_title'] ?? null, 60) ?: self::oneLine($file['extension'] ?? null, 10);
            $labels[] = "[$kind attachment" . ($name !== '' ? " $name" : '') . ', content not shown]';
        }

        return implode(' ', array_filter([$deleted, $body, ...$labels], fn ($it) => $it !== ''));
    }

    /** @param array<string, mixed> $message */
    public static function subject(array $message): string
    {
        return self::oneLine(self::attributes($message)['email']['subject'] ?? null, 160);
    }

    /** Text around the first match, accent- and case-insensitive. */
    public static function snippet(string $content, string $query): string
    {
        $at = mb_strpos(self::fold($content), self::fold($query));

        if ($at === false) {
            return mb_strlen($content) > self::SNIPPET_AFTER ? mb_substr($content, 0, self::SNIPPET_AFTER) . '…' : $content;
        }

        $start = max(0, $at - self::SNIPPET_BEFORE);
        $end = min(mb_strlen($content), $at + mb_strlen($query) + self::SNIPPET_AFTER);

        return ($start > 0 ? '…' : '') . mb_substr($content, $start, $end - $start) . ($end < mb_strlen($content) ? '…' : '');
    }

    public static function clock(mixed $seconds, ?string $timeZone): string
    {
        if (!is_int($seconds) && !is_float($seconds)) {
            return 'time unknown';
        }

        try {
            $zone = new DateTimeZone($timeZone ?: 'UTC');
        } catch (Throwable) {
            $zone = new DateTimeZone('UTC');
        }

        return (new DateTimeImmutable('@' . (int) $seconds))->setTimezone($zone)->format('Y-m-d H:i');
    }

    /**
     * Newest messages within the budget, oldest first.
     *
     * @param list<array<string, mixed>> $messages oldest first, as Chat pages them
     * @return array{lines: string[], earlierBefore: ?int}
     */
    public static function format(array $messages, ?int $earlierBefore, ?string $timeZone): array
    {
        $lines = [];
        $chars = 0;
        $truncated = false;

        foreach (array_reverse($messages) as $message) {
            $who = self::speaker($message);
            $content = $who ? self::text($message) : '';

            if (!$who || $content === '') {
                continue;
            }

            $id = is_int($message['id'] ?? null) && $message['id'] > 0 ? $message['id'] : null;
            $subject = self::subject($message);
            $length = mb_strlen($content);
            $body = $length > self::MESSAGE_CHARS
                ? mb_substr($content, 0, self::MESSAGE_CHARS) . '… (shortened: ' . self::MESSAGE_CHARS . " of $length characters" .
                    ($id ? "; read with messageId $id and offset " . self::MESSAGE_CHARS . ' for the rest' : '') . ')'
                : $content;
            $line = '[' . self::clock($message['created_at'] ?? null, $timeZone) . '] ' .
                ($id ? "#$id " : '') . $who . ($subject !== '' ? " (email \"$subject\")" : '') . ': ' . $body;

            if ($chars + mb_strlen($line) > self::CHARS && $lines) {
                $truncated = true;

                break;
            }

            array_unshift($lines, ['id' => $id, 'line' => $line]);
            $chars += mb_strlen($line) + 1;
        }

        return [
            'lines' => array_column($lines, 'line'),
            'earlierBefore' => $truncated ? ($lines[0]['id'] ?? $earlierBefore) : $earlierBefore,
        ];
    }

    /** @param array<string, mixed> $message @return array<string, mixed> content_attributes may be a JSON string. */
    private static function attributes(array $message): array
    {
        $attributes = $message['content_attributes'] ?? null;

        if (is_string($attributes)) {
            $attributes = json_decode($attributes, true);
        }

        return is_array($attributes) ? $attributes : [];
    }

    private static function oneLine(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, $max) : '';
    }

    private static function fold(string $value): string
    {
        $folded = class_exists('Normalizer') ? (string) \Normalizer::normalize($value, \Normalizer::FORM_D) : $value;

        return mb_strtolower((string) preg_replace('/\p{Mn}/u', '', $folded));
    }
}
