<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Bounded, resumable repair/expiry/reconciliation sweep. No provider resends. */
class ManagedCampaignMaintenance
{
    public function __construct(
        private EntityManager $em, private ManagedCampaignImport $imports, private ManagedCampaignExecution $execution,
        private ManagedCampaignBridge $bridge, private Config $config, private Log $log,
    ) {}

    public function run(): void
    {
        // Persist a keyset cursor in Config, so a large history cannot starve later campaigns.
        $after = (string) $this->config->get('managedCampaignSweepCursor', '');
        $rows = $this->em->getRDBRepository('WhatsAppCampaign')->where(['managedByChatwoot' => true, 'id>' => $after])
            ->order('id')->limit(0, 100)->find();
        foreach ($rows as $campaign) {
            $pdo = $this->em->getPDO();
            $name = 'managed-campaign-work:' . $campaign->getId();
            $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $lock->execute([$name]);
            $locked = (int) $lock->fetchColumn() === 1;
            try {
                if ($locked) $this->maintain($this->em->getEntityById('WhatsAppCampaign', $campaign->getId()));
            }
            catch (\Throwable) {
                $this->log->warning('Managed campaign maintenance error.', ['campaignId' => $campaign->getId(), 'stage' => 'reconciliation']);
            } finally {
                if ($locked) {
                    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([$name]);
                }
            }
            $after = $campaign->getId();
        }
        $this->config->set('managedCampaignSweepCursor', count($rows) === 100 ? $after : '');
        $this->config->save();
    }

    private function maintain(Entity $campaign): void
    {
        if ($campaign->get('managedCancelRequested') && $campaign->get('managedState') !== 'Cancelled') $campaign = $this->execution->cancel($campaign);
        $state = $campaign->get('managedState');
        $expired = ($state === 'Importing' && $this->age($campaign->get('managedProgressAt')) >= 86400) ||
            ($state === 'Ready' && $this->age($campaign->get('managedReadyAt')) >= 86400);
        if ($expired) {
            $this->em->getTransactionManager()->run(function () use (&$campaign): void {
                $campaign = $this->imports->lock($campaign->getId());
                if (!in_array($campaign->get('managedState'), ['Ready', 'Importing'], true)) return;
                $time = $campaign->get($campaign->get('managedState') === 'Ready' ? 'managedReadyAt' : 'managedProgressAt');
                if ($this->age($time) < 86400) return;
                $campaign->set(['managedState' => 'Expired', 'managedTerminatedAt' => date('Y-m-d H:i:s')]);
                $this->imports->save($campaign);
            });
        }
        if (in_array($campaign->get('managedState'), ['Preparing', 'Sending'], true)) {
            $age = $this->age($campaign->get('managedProgressAt'));
            if ($age > 900) $this->log->warning('Managed campaign stalled.', ['campaignId' => $campaign->getId(), 'stage' => $state, 'queueAge' => $age]);
            $job = $this->em->getEntityById('Job', (string) $campaign->get('managedJobId'));
            if (!$job || !in_array($job->get('status'), ['Pending', 'Running'], true) ||
                ($job->get('status') === 'Running' && $this->age($job->get('modifiedAt')) > 900)) {
                $this->em->getTransactionManager()->run(function () use ($campaign): void {
                    $fresh = $this->imports->lock($campaign->getId());
                    if ($fresh->get('managedJobId') === $campaign->get('managedJobId')) $this->imports->schedule($fresh);
                });
            }
        }
        if ($campaign->get('managedConfirmedAt') && !$campaign->get('managedPurgedAt')) $this->reconcile($campaign);
        $terminal = $campaign->get('managedTerminatedAt');
        if (!$terminal || $campaign->get('managedPurgedAt')) return;
        if ($campaign->get('managedConfirmedAt') && $this->age($terminal) < 90 * 86400) return;
        $this->purge($campaign);
    }

    private function reconcile(Entity $campaign): void
    {
        $result = $this->bridge->call($campaign, 'receipts', ['after' => (int) $campaign->get('managedReceiptCursor')]);
        if (!isset($result['payload'])) throw new \RuntimeException('receipt_reconciliation_failed');
        foreach ($result['payload'] as $receipt) {
            $row = $this->em->getRDBRepository('WhatsAppCampaignContact')->where([
                'whatsAppCampaignId' => $campaign->getId(), 'managedSendKey' => $receipt['send_key'],
            ])->findOne();
            if (!$row) continue;
            if ($row->get('managedDispatch') !== 'accepted') $this->execution->accepted($campaign, $row, $receipt);
            else {
                $this->execution->delivery($row, $receipt['delivery'] ?? 'unknown');
            }
        }
        $campaign->set('managedReceiptCursor', $result['next_cursor'] ?? 0);
        $this->imports->save($campaign);
    }

    private function purge(Entity $campaign): void
    {
        $result = $this->bridge->call($campaign, 'purge');
        if (!array_key_exists('purged', $result)) throw new \RuntimeException('snapshot_purge_failed');
        if (!$result['purged']) return; // Bounded Chat cleanup continues on the next sweep.
        $pdo = $this->em->getPDO();
        foreach (['whats_app_campaign_source', 'whats_app_campaign_import_chunk'] as $table) {
            $delete = $pdo->prepare("DELETE FROM {$table} WHERE campaign_id = ? LIMIT 500");
            $delete->execute([$campaign->getId()]);
            if ($delete->rowCount() === 500) return;
        }
        $clear = $pdo->prepare("UPDATE whats_app_campaign_contact SET managed_payload = NULL, processed_params = NULL,
            phone_number = CONCAT('purged:', id)
            WHERE whats_app_campaign_id = ? AND managed_payload IS NOT NULL LIMIT 500");
        $clear->execute([$campaign->getId()]);
        if ($clear->rowCount() === 500) return;
        $this->em->getTransactionManager()->run(function () use ($campaign): void {
            $this->em->getRDBRepository('ChatwootAccount')->where(['id' => $campaign->get('chatwootAccountId')])->forUpdate()->findOne();
            $campaign = $this->imports->lock($campaign->getId());
            $campaign->set(['managedComposition' => null, 'managedTemplate' => null, 'managedMedia' => null,
                'managedBytes' => 0, 'managedPurgedAt' => date('Y-m-d H:i:s')]);
            $this->imports->save($campaign);
        });
    }

    private function age(?string $time): int { return $time ? max(0, time() - strtotime($time . ' UTC')) : PHP_INT_MAX; }
}
