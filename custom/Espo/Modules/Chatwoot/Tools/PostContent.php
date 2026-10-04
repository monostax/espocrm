<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\References;

class PostContent
{
    public static function validate(string $post, mixed $state): void
    {
        if ($state === null || $state === '') return;
        if (!is_string($state)) throw new BadRequest('Invalid post editor state.');
        $rich = array_map(References::url(...), References::fromState($state));
        $portable = array_map(References::url(...), References::fromMarkdown($post));
        sort($rich);
        sort($portable);
        if ($rich !== $portable) throw new BadRequest('Post references do not match editor state.');
    }
}
