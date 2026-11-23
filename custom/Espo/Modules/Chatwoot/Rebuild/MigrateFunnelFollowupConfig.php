<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * One-time migration moving followupsEnabled and followupBreakoutLimit from
 * ChatwootAccountUserMembership to the Funnel(s) managed by each AI agent.
 */
class MigrateFunnelFollowupConfig implements RebuildAction
{
    public const MIGRATED_AT = 'funnelFollowupConfigMigratedAt';

    public function __construct(
        private Config $config,
        private ConfigWriter $configWriter,
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        if ($this->config->get(self::MIGRATED_AT)) {
            return;
        }

        $pdo = $this->entityManager->getPDO();
        try {
            $rows = $pdo->query(
                "SELECT mf.funnel_id, "
                . "  MIN(CASE WHEN m.followups_enabled = 0 THEN 0 ELSE 1 END) AS followups_enabled, "
                . "  MAX(m.followup_breakout_limit) AS followup_breakout_limit "
                . "FROM chatwoot_account_user_membership_funnel mf "
                . "JOIN chatwoot_account_user_membership m ON m.id = mf.chatwoot_account_user_membership_id "
                . "WHERE mf.deleted = 0 AND m.deleted = 0 AND m.is_a_i = 1 "
                . "GROUP BY mf.funnel_id"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare(
                "UPDATE funnel "
                . "SET followups_enabled = :followupsEnabled, "
                . "    followup_breakout_limit = COALESCE(followup_breakout_limit, :followupBreakoutLimit) "
                . "WHERE id = :funnelId AND deleted = 0"
            );

            foreach ($rows as $row) {
                $stmt->execute([
                    ':followupsEnabled' => (int) ($row['followups_enabled'] ?? 1),
                    ':followupBreakoutLimit' => $row['followup_breakout_limit'] !== null
                        ? (int) $row['followup_breakout_limit']
                        : null,
                    ':funnelId' => $row['funnel_id'],
                ]);
            }
        } catch (Throwable $e) {
            // Safe if the legacy membership columns were already dropped.
            $this->log->warning('MigrateFunnelFollowupConfig skipped legacy column copy: ' . $e->getMessage());
        }

        $this->configWriter->set(self::MIGRATED_AT, gmdate('Y-m-d H:i:s'));
        $this->configWriter->save();
    }
}
