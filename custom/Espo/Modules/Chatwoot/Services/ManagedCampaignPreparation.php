<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Util;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ManagedCampaignPreparation
{
    private const BATCH_BYTES = 1024 * 1024;

    public function __construct(
        private EntityManager $em, private ManagedCampaignAccess $access, private ManagedCampaignImport $imports,
        private ManagedCampaignPayload $payload, private ManagedCampaignRecipients $recipients, private Log $log,
    ) {}

    public function process(Entity $campaign): void
    {
        try {
            $ctx = $this->access->campaign($campaign);
            if (!$campaign->get('managedTemplate') && $campaign->get('messageMode') === 'Template') {
                $template = $this->payload->validate(ManagedCampaignPayload::array($campaign->get('managedComposition')), $ctx,
                    $campaign->get('managedMedia') ? ManagedCampaignPayload::array($campaign->get('managedMedia')) : null);
                $campaign->set('managedTemplate', (object) $template);
                $this->imports->save($campaign);
            } elseif ($campaign->get('messageMode') === 'FreeText') {
                $this->payload->validate(ManagedCampaignPayload::array($campaign->get('managedComposition')), $ctx,
                    $campaign->get('managedMedia') ? ManagedCampaignPayload::array($campaign->get('managedMedia')) : null);
            }
            $rows = $this->em->getRDBRepository('WhatsAppCampaignSource')->where(['campaignId' => $campaign->getId(), 'prepared' => false])
                ->order('id')->limit(0, 500)->find();
            $batch = [];
            $bytes = 0;
            foreach ($rows as $source) {
                // Contact writes intentionally survive a cancelled/failed preparation.
                $record = ManagedCampaignPayload::array($source->get('record'));
                $reason = $record['exclusion'] ?? null;
                $phone = $record['phone_number'] ?? '';
                if (!$reason && !preg_match('/^\+[1-9][0-9]{6,14}$/D', $phone)) $reason = 'invalid_number';
                $contact = $reason ? null : $this->recipients->resolve($record, $ctx);
                if ($contact) $reason = $this->recipients->exclusion($contact, $phone, $ctx);
                $json = $reason ? null : $this->payload->freeze($campaign, $contact, $phone, $ctx);
                $batch[] = ['id' => $source->getId(), 'phone' => $phone, 'reason' => $reason, 'json' => $json, 'contactId' => $contact?->getId()];
                $bytes += strlen($json ?? '');
                // At most one 500-source page, or 1 MiB plus one capped payload.
                // Identity synchronization remains outside the snapshot transaction.
                if ($bytes >= self::BATCH_BYTES) {
                    $campaign = $this->storeBatch($campaign, $batch);
                    if ($campaign->get('managedState') !== 'Preparing' || $campaign->get('managedCancelRequested')) return;
                    $batch = [];
                    $bytes = 0;
                }
            }
            if ($batch) $campaign = $this->storeBatch($campaign, $batch);
            $this->em->getTransactionManager()->run(function () use ($campaign): void {
                $campaign = $this->imports->lock($campaign->getId());
                if ($campaign->get('managedState') !== 'Preparing' || $campaign->get('managedCancelRequested')) return;
                if ((int) $campaign->get('managedPreparedCount') < (int) $campaign->get('managedExpectedCount')) {
                    $this->imports->schedule($campaign);
                    return;
                }
                if (!$campaign->get('managedEligibleCount')) throw new BadRequest('empty_audience');
                $campaign->set(['managedState' => 'Ready', 'managedReadyAt' => date('Y-m-d H:i:s'), 'managedProgressAt' => date('Y-m-d H:i:s')]);
                $this->imports->save($campaign);
            });
        } catch (\Throwable $e) {
            $this->em->getTransactionManager()->run(function () use ($campaign, $e): void {
                $campaign = $this->imports->lock($campaign->getId());
                if ($campaign->get('managedState') !== 'Preparing') return;
                $known = ['ambiguous_identity', 'missing_required_value', 'field_unavailable', 'unresolved_expression', 'snapshot_budget_exceeded', 'empty_audience'];
                $code = in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'preparation_failed';
                $campaign->set(['managedState' => 'Preparation failed', 'managedError' => $code, 'managedTerminatedAt' => date('Y-m-d H:i:s')]);
                $this->imports->save($campaign);
                $this->log->warning('Managed campaign preparation failed.', ['campaignId' => $campaign->getId(), 'stage' => 'prepare', 'error' => $code]);
            });
        }
    }

    /** Atomically persist one bounded snapshot batch, including its quota and digest. */
    private function storeBatch(Entity $campaign, array $batch): Entity
    {
        return $this->em->getTransactionManager()->run(function () use ($campaign, $batch): Entity {
            $campaign = $this->imports->lock($campaign->getId());
            if ($campaign->get('managedState') !== 'Preparing' || $campaign->get('managedCancelRequested')) return $campaign;
            $fresh = [];
            foreach ($this->em->getRDBRepository('WhatsAppCampaignSource')->select(['id'])->where([
                'campaignId' => $campaign->getId(), 'id' => array_column($batch, 'id'), 'prepared' => false,
            ])->forUpdate()->find() as $source) $fresh[$source->getId()] = true;
            $batch = array_values(array_filter($batch, fn ($row) => isset($fresh[$row['id']])));
            if (!$batch) return $campaign;

            $phones = array_values(array_unique(array_column(array_filter($batch, fn ($row) => !$row['reason']), 'phone')));
            $recipients = [];
            if ($phones) {
                foreach ($this->em->getRDBRepository('WhatsAppCampaignContact')->select(['id', 'phoneNumber', 'contactId', 'managedFingerprint'])
                    ->where(['whatsAppCampaignId' => $campaign->getId(), 'phoneNumber' => $phones])->find() as $row) {
                    $recipients[$row->get('phoneNumber')] = $row;
                }
            }
            $new = $this->em->getCollectionFactory()->create('WhatsAppCampaignContact');
            $bytes = 0;
            $excluded = 0;
            $digest = $campaign->get('managedDigest');
            $updates = [];
            $now = date('Y-m-d H:i:s');
            foreach ($batch as $item) {
                $recipient = null;
                if (!$item['reason']) {
                    $recipient = $recipients[$item['phone']] ?? null;
                    if ($recipient && $recipient->get('contactId') !== $item['contactId']) throw new BadRequest('ambiguous_identity');
                    if (!$recipient) {
                        $recipient = $this->em->getNewEntity('WhatsAppCampaignContact');
                        $recipient->set([
                            'id' => Util::generateId(), 'whatsAppCampaignId' => $campaign->getId(),
                            'chatwootAccountId' => $campaign->get('chatwootAccountId'), 'contactId' => $item['contactId'],
                            'phoneNumber' => $item['phone'], 'status' => 'Pending', 'managedSendKey' => bin2hex(random_bytes(24)),
                            'managedPayload' => $item['json'], 'managedFingerprint' => hash('sha256', $item['json']),
                            'managedDispatch' => 'pending', 'managedDelivery' => 'unknown', 'createdAt' => $now, 'modifiedAt' => $now,
                        ]);
                        $new[] = $recipient;
                        $recipients[$item['phone']] = $recipient;
                        $bytes += strlen($item['json']);
                    }
                } else {
                    $excluded++;
                }
                $updates[] = ['id' => $item['id'], 'reason' => $item['reason'], 'recipientId' => $recipient?->getId()];
                $digest = hash('sha256', $digest . "\n" . $item['id'] . "\n" . ($item['reason'] ?? $recipient->get('managedFingerprint')));
            }
            $campaign->set([
                'managedPreparedCount' => (int) $campaign->get('managedPreparedCount') + count($batch),
                'managedEligibleCount' => (int) $campaign->get('managedEligibleCount') + count($new),
                'managedExcludedCount' => (int) $campaign->get('managedExcludedCount') + $excluded,
                'managedProgressAt' => $now, 'managedDigest' => $digest,
            ]);
            if ($bytes) $this->imports->reserve($campaign, $bytes);
            else $this->imports->save($campaign);
            // Internal sealed snapshots only; no ordinary audience/enrollment hooks.
            // The locked campaign, unique phone/send-key indexes, and transaction
            // preserve the same guards as individual managed inserts.
            $this->em->getMapper()->massInsert($new);
            $this->markSources($campaign->getId(), $updates);
            return $campaign;
        });
    }

    private function markSources(string $campaignId, array $rows): void
    {
        $case = implode(' ', array_fill(0, count($rows), 'WHEN ? THEN ?'));
        $ids = implode(', ', array_fill(0, count($rows), '?'));
        $params = [];
        foreach ($rows as $row) array_push($params, $row['id'], $row['reason']);
        foreach ($rows as $row) array_push($params, $row['id'], $row['recipientId']);
        $params[] = $campaignId;
        array_push($params, ...array_column($rows, 'id'));
        $stmt = $this->em->getPDO()->prepare("UPDATE whats_app_campaign_source SET prepared = 1,
            reason = CASE id {$case} END, recipient_id = CASE id {$case} END
            WHERE campaign_id = ? AND id IN ({$ids})");
        $stmt->execute($params);
    }
}
