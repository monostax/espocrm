<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;

/** Value-free historical application status; concrete destination links require their own record ACL. */
final class ApplicationStatus
{
    public static function fromRow(array $row): array
    {
        if (($row['application_hash'] ?? null) === null) {
            if (($row['application_receipt'] ?? null) !== null) throw new Conflict('Application receipt is unbound.');
            return ['applied' => false,'appliedAt' => null,'appliedById' => null];
        }
        try { $receipt = json_decode($row['application_receipt'] ?? '',true,32,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new Conflict('Application receipt is invalid.'); }
        if (($receipt['purchaseId'] ?? $receipt['id'] ?? null) !== $row['id'] || ($receipt['tenantId'] ?? null) !== $row['tenant_id'] ||
            !is_string($receipt['appliedAt'] ?? null) || !is_string($receipt['actorId'] ?? null)) throw new Conflict('Application receipt binding differs.');
        return ['applied' => true,'appliedAt' => $receipt['appliedAt'],'appliedById' => $receipt['actorId']];
    }
}
