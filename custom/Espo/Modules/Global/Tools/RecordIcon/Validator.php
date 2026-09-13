<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Tools\RecordIcon;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Metadata;
use stdClass;

/** Shared validation for entities that opt in to record icons. */
class Validator
{
    private ?array $emojiSet = null;

    public function __construct(private Metadata $metadata) {}

    public function validate(mixed $icon): void
    {
        if ($icon === null) {
            return;
        }

        if (
            !$icon instanceof stdClass ||
            count(get_object_vars($icon)) !== 2 ||
            !isset($icon->type, $icon->value) ||
            !is_string($icon->type) || !is_string($icon->value) ||
            strlen($icon->value) > 256
        ) {
            throw new BadRequest('Invalid record icon.');
        }

        if ($icon->type === 'icon') {
            $classes = array_merge(
                $this->metadata->get('app.clientIcons.classList', []),
                $this->metadata->get('app.recordIcons.fontAwesomeClassList', []),
            );
            if (in_array($icon->value, $classes, true)) {
                return;
            }
        }

        if ($icon->type === 'emoji') {
            $this->emojiSet ??= array_fill_keys(json_decode(
                file_get_contents(__DIR__ . '/../../Resources/record-icon-emojis.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ), true);

            if (isset($this->emojiSet[$icon->value])) {
                return;
            }
        }

        throw new BadRequest('Choose a supported emoji or icon.');
    }
}
