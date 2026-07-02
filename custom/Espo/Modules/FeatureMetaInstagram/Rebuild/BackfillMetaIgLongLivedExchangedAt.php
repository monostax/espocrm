<?php

namespace Espo\Modules\FeatureMetaInstagram\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Rebuild action: backfill `metaIgLongLivedExchangedAt` on meta-instagram
 * OAuthAccounts whose token is evidently already long-lived.
 *
 * Context:
 *   - `ChatwootInboxIntegration::activateInstagram` uses
 *     `metaIgLongLivedExchangedAt` to decide whether to POST to
 *     `graph.instagram.com/access_token?grant_type=ig_exchange_token`.
 *   - That endpoint accepts short-lived tokens only; if it is called with
 *     a long-lived token, Meta returns OAuthException code 190 and
 *     activation fails.
 *   - The field was added *after* some accounts were already successfully
 *     exchanged, so their flag is NULL even though the stored token is
 *     long-lived. Short-lived tokens live only ~1 hour, so any OAuthAccount
 *     whose `expires_at` is more than 2 days in the future must be
 *     long-lived already.
 *
 * This action runs idempotently; it only touches rows with
 *   `meta_ig_long_lived_exchanged_at IS NULL`
 * AND
 *   `expires_at > NOW() + INTERVAL 2 DAY`
 * AND
 *   `provider_id = (any provider with provider='meta-instagram')`.
 */
class BackfillMetaIgLongLivedExchangedAt implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $pdo = $this->entityManager->getPDO();
        $isPg = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql';

        // Bail out if the column doesn't exist yet (first rebuild after
        // pulling in the entityDefs change but before schema rebuild).
        // SHOW COLUMNS is MySQL-only; PostgreSQL probes information_schema.
        $colCheck = $isPg
            ? $pdo->query(
                "SELECT 1 FROM information_schema.columns " .
                "WHERE table_schema = current_schema() " .
                "AND table_name = 'o_auth_account' " .
                "AND column_name = 'meta_ig_long_lived_exchanged_at' LIMIT 1"
            )
            : $pdo->query(
                "SHOW COLUMNS FROM o_auth_account LIKE 'meta_ig_long_lived_exchanged_at'"
            );

        if (!$colCheck || !$colCheck->fetch()) {
            $this->log->info(
                'FeatureMetaInstagram: Skipping backfill — ' .
                'o_auth_account.meta_ig_long_lived_exchanged_at column not present yet.'
            );

            return;
        }

        // MySQL's multi-table UPDATE ... INNER JOIN has no direct
        // PostgreSQL equivalent; the PG branch uses UPDATE ... FROM.
        // The MySQL string is kept byte-identical to the original.
        if ($isPg) {
            $sql = <<<SQL
                UPDATE o_auth_account oa
                SET meta_ig_long_lived_exchanged_at = COALESCE(oa.modified_at, (NOW() AT TIME ZONE 'UTC'))
                FROM o_auth_provider op
                WHERE op.id = oa.provider_id
                  AND op.provider = 'meta-instagram'
                  AND oa.deleted = false
                  AND oa.meta_ig_long_lived_exchanged_at IS NULL
                  AND oa.access_token IS NOT NULL
                  AND oa.expires_at IS NOT NULL
                  AND oa.expires_at > ((NOW() AT TIME ZONE 'UTC') + INTERVAL '2 days')
            SQL;
        } else {
            $sql = <<<SQL
                UPDATE o_auth_account oa
                INNER JOIN o_auth_provider op
                    ON op.id = oa.provider_id
                    AND op.provider = 'meta-instagram'
                SET oa.meta_ig_long_lived_exchanged_at = COALESCE(oa.modified_at, UTC_TIMESTAMP())
                WHERE oa.deleted = 0
                  AND oa.meta_ig_long_lived_exchanged_at IS NULL
                  AND oa.access_token IS NOT NULL
                  AND oa.expires_at IS NOT NULL
                  AND oa.expires_at > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 DAY)
            SQL;
        }

        $affected = $pdo->exec($sql);

        $this->log->info(
            'FeatureMetaInstagram: Backfilled metaIgLongLivedExchangedAt on ' .
            (int) $affected . ' OAuthAccount row(s).'
        );
    }
}
