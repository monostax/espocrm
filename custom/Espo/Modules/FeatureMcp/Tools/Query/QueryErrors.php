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

namespace Espo\Modules\FeatureMcp\Tools\Query;

use Espo\Modules\Chatwoot\Tools\RecordQuery\QueryError;

/**
 * What a record query error says, in the words of the "Consulta avançada" box (Chatwoot `advancedFilters.json`
 * RECORD_QUERY.ERRORS, en): keep them in step. The model reads these, so they stay in English.
 */
class QueryErrors
{
    private const array MESSAGES = [
        'empty' => 'Type a query.',
        'too_long' => 'The query is too long.',
        'too_deep' => 'Too many relationship levels at "{token}" (3 at most).',
        'unterminated_string' => 'A quoted text is missing its closing quote.',
        'unexpected_character' => 'Unexpected character "{token}".',
        'unexpected_token' => 'Unexpected "{token}". Join conditions with AND or OR.',
        'unexpected_end' => 'The query ends too early.',
        'expected_close' => 'A closing bracket is missing before "{token}".',
        'expected_field' => 'A field name is expected here.',
        'expected_operator' => 'An operator such as =, ~ or IN is expected before "{token}".',
        'expected_empty' => 'Use IS EMPTY or IS NOT EMPTY.',
        'expected_list' => 'IN needs a list in brackets, for example IN ("a", "b").',
        'expected_value' => 'A value is expected here. Put text in quotes.',
        'unknown_field' => '"{token}" is not a field you can filter by.',
        'unknown_link' => '"{token}" is not a relationship you can filter by.',
        'bad_operator' => 'This operator does not work with "{token}".',
        'bad_value' => 'This value does not fit "{token}".',
        'too_complex' => 'The query asks for too much at once. Use fewer conditions or relationships.',
        'expected_condition' => '"{token}" needs a condition inside the brackets.',
        'expected_method' => 'After .where(…) use .any, .all, .none, .count or another .where.',
        'expected_count' => '.count() is compared with a whole number, for example .count() >= 2.',
    ];

    /** @return object{code: string, position: int, token: ?string, message: string} */
    public static function describe(QueryError $error): object
    {
        $message = self::MESSAGES[$error->errorCode] ?? 'This query is not valid.';

        return (object) [
            'code' => $error->errorCode,
            'position' => $error->position,
            'token' => $error->token,
            'message' => str_replace('{token}', (string) $error->token, $message),
        ];
    }
}
