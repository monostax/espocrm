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

namespace Espo\Modules\FeatureMcp\Tools\Language;

/**
 * The advanced filter's syntax for models: what the "Consulta avançada" hint says (Chatwoot `advancedFilters.json`
 * RECORD_QUERY.HINT), with the rest of the language the parser accepts (Chatwoot Tools/RecordQuery/Parser.php).
 */
class Guide
{
    public const array LINES = [
        'Filters one record type. Combine conditions with AND, OR, NOT and brackets.',
        'Compare a field: = != > < >= <=, ~ (contains), !~ (does not contain), IN ("a", "b"), NOT IN (...), ' .
            'IS EMPTY, IS NOT EMPTY, and HAS for multi-value fields (tags HAS "vip").',
        'Values: "text" in double quotes (\" inside), numbers, true, false, me (the signed-in user, for user ' .
            'links), dates as "2026-01-31", today, and relative dates -30d, -2w, -3m, -1y (days, weeks, months, ' .
            'years ago; + for ahead).',
        'Enum fields compare with the stored value, not the label (see options).',
        'One related record reads with a dot: account.name ~ "acme", assignedUser = me.',
        'Many related records say how many must match: contacts.any(...), .all(...) (at least one, and every ' .
            'one), .none(...), .count() >= 2, .count(condition) = 0; .where(...) narrows them first: ' .
            'contacts.where(origin = "enrichment").all(...). contacts(...) is short for contacts.any(...).',
        'Field and list names are API names. Up to 3 relationship levels and 4000 characters; a query that asks ' .
            'for too much at once is refused (too_complex).',
        'It only sees records and fields the signed-in user can see.',
        'Example: source = "Indicação" AND (account.industry IN ("SaaS", "Fintech") OR tags HAS "vip") AND ' .
            'opportunities.any(amount > 1000) AND createdAt > -30d AND NOT assignedUser IS EMPTY',
    ];
}
