<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

/** CRM's default entity/link width is 17. Existing wider IDs remain readable;
 * new service-owned records must fit both default and expanded CRM schemas. */
final class RecordId
{
    public static function generate(): string
    {
        return substr(bin2hex(random_bytes(9)), 0, 17);
    }
}
