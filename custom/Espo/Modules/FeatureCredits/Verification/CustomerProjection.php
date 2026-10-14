<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

/**
 * Provider-neutral customer projections for phone verification (D23, customer-contracts.md).
 * Never exposes supplier names, internal policy hashes, claim tokens, or raw internal diagnostics.
 */
final class CustomerProjection
{
    private static function only(array $value, array $keys): array
    {
        return array_intersect_key($value, array_flip($keys));
    }

    public static function context(array $data): array
    {
        $email = ($data['verificationKind'] ?? Kind::PHONE) === Kind::EMAIL;
        $rows = [];
        foreach ($data['rows'] ?? [] as $key => $row) {
            $projected = self::only($row, [
                'key',
                'eligible',
                'reason',
                $email ? 'canonicalEmail' : 'canonicalPhone',
                'ownershipStatus',
                'contextRevision',
            ]);
            $projected['actions'] = self::only($row['actions'] ?? [], [
                'canVerify',
                'canRefresh',
                'requiresPaidRefresh',
            ]);
            $projected['activeOperation'] = isset($row['activeOperation']) && is_array($row['activeOperation'])
                ? self::only($row['activeOperation'], ['operationId', 'usageId', 'state', 'admittedAt', 'priceCredits'])
                : null;
            $projected['currentResult'] = isset($row['currentResult']) && is_array($row['currentResult'])
                ? self::evidence($row['currentResult'])
                : null;
            $projected['lastFailedAttempt'] = isset($row['lastFailedAttempt']) && is_array($row['lastFailedAttempt'])
                ? self::only($row['lastFailedAttempt'], ['operationId', 'usageId', 'state', 'outcome', 'completedAt', 'settledCredits'])
                : null;
            $rows[$key] = $projected;
        }

        return [
            ...($email ? ['verificationKind' => Kind::EMAIL] : []),
            'tenantId' => $data['tenantId'],
            'contactId' => $data['contactId'],
            'observedAt' => $data['observedAt'],
            'priceCredits' => $data['priceCredits'],
            'available' => $data['available'],
            'reason' => $data['reason'],
            'contact' => $email ? (object) [] : self::only($data['contact'] ?? [], [
                'nameReady',
                'hasDateOfBirth',
                'dateOfBirth',
                'dateOfBirthEditable',
            ]),
            'rows' => (object) $rows,
        ];
    }

    public static function evidence(array $result): array
    {
        if (($result['verificationKind'] ?? Kind::PHONE) === Kind::EMAIL) {
            return [
                ...self::only($result, ['operationId', 'usageId', 'outcome', 'completedAt', 'freshUntil', 'fresh', 'stale', 'settledCredits']),
                'emailEvidence' => self::emailEvidence($result['emailEvidence'] ?? null),
            ];
        }
        return [
            'operationId' => $result['operationId'],
            'usageId' => $result['usageId'],
            'outcome' => $result['outcome'],
            'matchedFields' => array_values($result['matchedFields'] ?? []),
            'fieldOutcomes' => isset($result['fieldOutcomes']) && is_array($result['fieldOutcomes'])
                ? self::only($result['fieldOutcomes'], ['firstName', 'lastName', 'dateOfBirth'])
                : null,
            'hasDateOfBirth' => (bool) ($result['hasDateOfBirth'] ?? false),
            'completedAt' => $result['completedAt'],
            'freshUntil' => $result['freshUntil'],
            'fresh' => (bool) ($result['fresh'] ?? false),
            'stale' => (bool) ($result['stale'] ?? false),
            'settledCredits' => $result['settledCredits'],
        ];
    }

    public static function operation(array $operation): array
    {
        if (($operation['verificationKind'] ?? Kind::PHONE) === Kind::EMAIL) {
            return [
                ...self::only($operation, ['verificationKind', 'id', 'usageId', 'reservationId', 'canonicalEmail',
                    'state', 'outcome', 'priceCredits', 'reservedCredits', 'settledCredits', 'admittedAt', 'completedAt',
                    'freshUntil', 'fresh', 'stale', 'erased', 'expired', 'identityChanged']),
                'emailEvidence' => self::emailEvidence($operation['emailEvidence'] ?? null),
            ];
        }
        return [
            'id' => $operation['id'],
            'usageId' => $operation['usageId'],
            'reservationId' => $operation['reservationId'],
            'canonicalPhone' => $operation['canonicalPhone'] ?? null,
            'state' => $operation['state'],
            'outcome' => $operation['outcome'] ?? null,
            'matchedFields' => array_values($operation['matchedFields'] ?? []),
            'fieldOutcomes' => isset($operation['fieldOutcomes']) && is_array($operation['fieldOutcomes'])
                ? self::only($operation['fieldOutcomes'], ['firstName', 'lastName', 'dateOfBirth'])
                : null,
            'hasDateOfBirth' => (bool) ($operation['hasDateOfBirth'] ?? false),
            'priceCredits' => $operation['priceCredits'],
            'reservedCredits' => $operation['reservedCredits'],
            'settledCredits' => $operation['settledCredits'],
            'admittedAt' => $operation['admittedAt'],
            'completedAt' => $operation['completedAt'],
            'freshUntil' => $operation['freshUntil'] ?? null,
            'fresh' => (bool) ($operation['fresh'] ?? false),
            'stale' => (bool) ($operation['stale'] ?? false),
            'erased' => (bool) ($operation['erased'] ?? false),
            'expired' => (bool) ($operation['expired'] ?? false),
            'identityChanged' => (bool) ($operation['identityChanged'] ?? false),
        ];
    }

    public static function status(array $receipt): array
    {
        return [
            'tenantId' => $receipt['tenantId'],
            'contactId' => $receipt['contactId'],
            ...(isset($receipt['requestKey']) ? ['requestKey' => $receipt['requestKey']] : []),
            ...(isset($receipt['resolution']) ? ['resolution' => $receipt['resolution']] : []),
            'operation' => self::operation($receipt['operation']),
        ];
    }

    private static function emailEvidence(?array $evidence): ?array
    {
        if ($evidence === null) return null;
        return [
            'score' => $evidence['score'] ?? null,
            'verifiedAt' => $evidence['verifiedAt'] ?? null,
            'flags' => (object) self::only($evidence['flags'] ?? [], ['is_disposable', 'is_accept_all', 'is_role', 'is_free']),
        ];
    }

    public static function history(array $history): array
    {
        return [
            'tenantId' => $history['tenantId'],
            'contactId' => $history['contactId'],
            'observedAt' => $history['observedAt'],
            'list' => array_map(self::operation(...), $history['list'] ?? []),
            'next' => isset($history['next']) && is_array($history['next'])
                ? self::only($history['next'], ['beforeAt', 'beforeId'])
                : null,
        ];
    }
}
