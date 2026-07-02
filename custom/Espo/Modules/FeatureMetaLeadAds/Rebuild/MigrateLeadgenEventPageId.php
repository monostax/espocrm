<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * One-shot data migration for MetaLeadgenEvent — repurposes the `page_id`
 * column from "Meta numeric page id" to the FOREIGN-ID of the `page` link
 * (CRM MetaFacebookPage id).
 *
 * Background:
 *  - Prior schema declared a scalar `pageId` (varchar) AND a `page` link.
 *    Both back the same column `page_id`. Webhook code wrote the Meta
 *    numeric id, so the `page` link was structurally broken (resolved to
 *    null because no MetaFacebookPage exists with a Meta-numeric CRM id).
 *  - New schema renames the scalar to `metaPageId` (column `meta_page_id`),
 *    leaving `page_id` exclusively for the link foreign-id (CRM id).
 *
 * Run-once contract:
 *  - Gated on the `metaLeadAdsLeadgenEventPageIdMigrated` config flag.
 *    Once set to true, every subsequent rebuild is a no-op cheap return —
 *    no SQL, no schema inspection.
 *  - The flag is set ONLY after a successful run that leaves zero remaining
 *    unmigrated rows, so a partial migration (e.g. table didn't yet have
 *    the meta_page_id column) will re-attempt on the next rebuild.
 *
 * Migration steps (when running):
 *  1. Copy the existing `page_id` value into `meta_page_id` for every row
 *     where `meta_page_id` is still NULL.
 *  2. Replace `page_id` with the resolved MetaFacebookPage CRM id (looked
 *     up via `meta_facebook_page.page_id` = the Meta numeric we just
 *     copied). Rows without a matching local Page get `page_id` set to
 *     NULL — the link is "unknown" until the page is synced.
 */
class MigrateLeadgenEventPageId implements RebuildAction
{
    private const FLAG_KEY = 'metaLeadAdsLeadgenEventPageIdMigrated';

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private ConfigWriter $configWriter,
        private Log $log,
    ) {}

    public function process(): void
    {
        // Run-once gate: cheapest possible no-op after first success.
        if ((bool) $this->config->get(self::FLAG_KEY) === true) {
            return;
        }

        try {
            $pdo = $this->entityManager->getPDO();
            $isPg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';

            // Defensive: only run if both columns exist. Avoids errors when
            // rebuild fires before the schema-rebuild step has added
            // meta_page_id (admin-supplied bootstrap order on fresh installs).
            if (!$this->columnExists($pdo, 'meta_leadgen_event', 'meta_page_id')) {
                $this->log->info('FeatureMetaLeadAds.MigrateLeadgenEventPageId: meta_page_id column not yet present; skipping (will retry next rebuild).');

                return;
            }

            if (!$this->columnExists($pdo, 'meta_leadgen_event', 'page_id')) {
                // Schema in a state we don't recognise — set the flag so we
                // don't loop. Operator can clear it manually if needed.
                $this->markDone();

                return;
            }

            // Fast path: if there are no rows at all, mark done immediately.
            $rowCount = (int) $pdo->query('SELECT COUNT(*) FROM meta_leadgen_event')->fetchColumn();

            if ($rowCount === 0) {
                $this->markDone();

                return;
            }

            $pdo->beginTransaction();

            // Pass 1: copy Meta-numeric pageId into the new meta_page_id
            // scalar column. Only for rows where the new column is NULL
            // (idempotent) AND the legacy value LOOKS LIKE a Meta numeric
            // (10..32 chars, all digits) — protects rows already converted
            // on a previous run that happened to have a 17-char CRM id.
            // Regex-match operator differs per driver (REGEXP vs ~).
            $sql1 = $isPg
                ? "UPDATE meta_leadgen_event
                     SET meta_page_id = page_id
                     WHERE deleted = false
                       AND meta_page_id IS NULL
                       AND page_id IS NOT NULL
                       AND page_id ~ '^[0-9]{10,32}$'"
                : "UPDATE meta_leadgen_event
                     SET meta_page_id = page_id
                     WHERE deleted = 0
                       AND meta_page_id IS NULL
                       AND page_id IS NOT NULL
                       AND page_id REGEXP '^[0-9]{10,32}$'";

            $copied = $pdo->exec($sql1);

            // Pass 2: now repoint page_id to the CRM id of the matching
            // MetaFacebookPage (resolved by meta_facebook_page.page_id =
            // our newly-copied meta_page_id). Rows without a local match
            // get NULL — the link is genuinely unknown.
            // MySQL's multi-table UPDATE ... LEFT JOIN has no PostgreSQL
            // equivalent (UPDATE ... FROM is inner-join-like), so the PG
            // branch uses a correlated scalar subquery, which also yields
            // NULL when no local Page matches.
            $sql2 = $isPg
                ? "UPDATE meta_leadgen_event e
                     SET page_id = (
                       SELECT p.id
                       FROM meta_facebook_page p
                       WHERE p.page_id = e.meta_page_id
                         AND p.deleted = false
                       LIMIT 1
                     )
                     WHERE e.deleted = false
                       AND e.meta_page_id IS NOT NULL
                       AND (e.page_id IS NULL OR LENGTH(e.page_id) <> 17)"
                : "UPDATE meta_leadgen_event e
                     LEFT JOIN meta_facebook_page p
                       ON p.page_id = e.meta_page_id
                       AND p.deleted = 0
                     SET e.page_id = p.id
                     WHERE e.deleted = 0
                       AND e.meta_page_id IS NOT NULL
                       AND (e.page_id IS NULL OR LENGTH(e.page_id) <> 17)";

            $repointed = $pdo->exec($sql2);

            $pdo->commit();

            $this->log->info(sprintf(
                'FeatureMetaLeadAds.MigrateLeadgenEventPageId: copied=%d, repointed=%d.',
                (int) $copied,
                (int) $repointed,
            ));

            $this->markDone();
        } catch (Throwable $e) {
            try {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // Suppress secondary failure during rollback.
            }

            // Do NOT set the flag — failure means we want to retry on the
            // next rebuild after the operator fixes whatever blocked us.
            $this->log->warning(
                'FeatureMetaLeadAds.MigrateLeadgenEventPageId: ' . $e->getMessage()
            );
        }
    }

    private function markDone(): void
    {
        try {
            $this->configWriter->set(self::FLAG_KEY, true);
            $this->configWriter->save();
        } catch (Throwable $e) {
            $this->log->warning(
                'FeatureMetaLeadAds.MigrateLeadgenEventPageId: failed to persist done-flag: ' . $e->getMessage()
            );
        }
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            // information_schema.columns exists on both engines; only the
            // schema-scoping function differs (DATABASE() vs current_schema()).
            $isPg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';

            $st = $isPg
                ? $pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = :t
                   AND column_name = :c"
                )
                : $pdo->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :t
                   AND COLUMN_NAME = :c"
                );
            $st->execute([':t' => $table, ':c' => $column]);

            return (int) $st->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
