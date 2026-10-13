<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use InvalidArgumentException;
use PDO;

/** Explicitly agreed opening allowance; never converts engagement counts or changes legacy history. */
final class LegacyMigration
{
    public function __construct(private WalletLock $walletLock, private Funding $funding) {}

    public function grant(string $tenantId, string $cutoverId, string $credits, ?string $expiresAt,
        string $legacyReference, string $approvalReference, string $actorId): array
    {
        GrantInput::identity($tenantId);
        GrantInput::identity($cutoverId);
        GrantInput::identity($actorId);
        if (Amount::fromString($credits)->compareTo(Amount::fromString('0')) <= 0) {
            throw new InvalidArgumentException('An agreed positive opening allowance is required.');
        }
        if ($expiresAt !== null) GrantInput::timestamp($expiresAt);
        foreach ([$legacyReference, $approvalReference] as $reference) {
            if (trim($reference) !== $reference || $reference === '' || strlen($reference) > 255 ||
                preg_match('/[\x00-\x1f\x7f]/', $reference)) {
                throw new InvalidArgumentException('Explicit legacy and approval references are required.');
            }
        }
        return $this->walletLock->run($tenantId, function (PDO $pdo, array $wallet, string $now) use (
            $tenantId, $cutoverId, $credits, $expiresAt, $legacyReference, $approvalReference, $actorId
        ): array {
            $q = $pdo->prepare('SELECT * FROM tenant_credit_cutover WHERE tenant_id = ?');
            $q->execute([$tenantId]);
            $cutover = $q->fetch(PDO::FETCH_ASSOC);
            if (!$cutover || $cutover['deleted'] || $cutover['id'] !== $cutoverId || $cutover['cutover_at'] > $now) {
                throw new Conflict('Migration requires the original effective tenant cutover.');
            }
            // One opening grant per cutover, regardless of retry keys, actors or source labels.
            // Funding binds all agreed terms and preserves the original receipt after consumption/expiry.
            return $this->funding->grantLocked($pdo, $wallet, $now, new GrantInput(
                $tenantId, 'migration', 'legacy-opening:' . strtolower($cutoverId), $credits,
                $cutover['cutover_at'], $expiresAt, (object) [
                    'formula' => 'legacy-opening-v1', 'cutoverId' => $cutoverId,
                    'cutoverHash' => $cutover['input_hash'], 'legacyReference' => $legacyReference,
                    'approvalReference' => $approvalReference,
                ], $actorId,
            ));
        });
    }
}
