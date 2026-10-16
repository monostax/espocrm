<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Util;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ManagedCampaignImport
{
    public const OPTIONS = ['silent' => true, 'managedCampaign' => true];
    public const ACTIVE_PREPARATION = ['Importing', 'Preparing', 'Ready'];

    public function __construct(
        private EntityManager $em, private ManagedCampaignAccess $access, private ManagedCampaignPayload $payload,
        private \Espo\Core\InjectableFactory $factory, private Log $log,
    ) {}

    public function dispatch(Entity $platform, array $data): array
    {
        $action = $data['action'] ?? '';
        $ctx = $this->access->context($platform, $data, in_array($action, ['begin', 'append', 'seal', 'confirm', 'retry'], true));
        if ($action === 'capabilities') return $this->payload->capabilities($ctx) + ['sender_id' => $this->access->integrationSenderId($ctx['account'])];
        if ($action === 'history') return $this->history($ctx, $data);
        if ($action === 'begin') return $this->begin($ctx, $data);
        $campaign = $this->owned($ctx, $data);
        try {
            return match ($action) {
            'append' => $this->append($campaign, $data), 'seal' => $this->seal($campaign),
            'read' => $this->summary($campaign), 'results' => $this->results($campaign, $data, $ctx),
            'confirm' => $this->summary($this->factory->create(ManagedCampaignExecution::class)->confirm($campaign, $data)),
            'cancel' => $this->cancel($campaign, $ctx),
            'retry' => $this->summary($this->factory->create(ManagedCampaignExecution::class)->retry($campaign)),
            default => throw new BadRequest('Unknown campaign operation.'),
            };
        } catch (BadRequest $e) {
            if ($e->getMessage() === 'snapshot_budget_exceeded') {
                $this->em->getTransactionManager()->run(function () use ($campaign): void {
                    $campaign = $this->lock($campaign->getId());
                    if ($campaign->get('managedState') !== 'Importing') return;
                    $campaign->set(['managedState' => 'Preparation failed', 'managedError' => 'snapshot_budget_exceeded',
                        'managedTerminatedAt' => date('Y-m-d H:i:s')]);
                    $this->save($campaign);
                });
            }
            throw $e;
        }
    }

    private function begin(array $ctx, array $data): array
    {
        if (!$ctx['inbox'] || !preg_match('/^[\w-]{16,100}$/D', (string) ($data['request_key'] ?? '')) ||
            !preg_match('/^[a-f0-9]{64}$/D', (string) ($data['fingerprint'] ?? '')) ||
            !preg_match('/^[a-f0-9]{64}$/D', (string) ($data['selection_digest'] ?? '')) ||
            !is_int($data['expected_count'] ?? null) || $data['expected_count'] < 1 || empty($data['operation_id'])) throw new BadRequest('Invalid import.');
        $key = hash('sha256', implode(':', [$ctx['platform']->getId(), $ctx['account']->getId(), $ctx['user']->getId(), $data['request_key']]));
        $campaign = $this->em->getTransactionManager()->run(function () use ($ctx, $data, $key): Entity {
            $account = $this->em->getRDBRepository('ChatwootAccount')->where(['id' => $ctx['account']->getId()])->forUpdate()->findOne();
            $existing = $this->em->getRDBRepository('WhatsAppCampaign')->where(['managedRequestKey' => $key])->findOne();
            if ($existing) {
                if ($existing->get('managedFingerprint') !== $data['fingerprint'] ||
                    (int) $existing->get('managedOperationId') !== (int) $data['operation_id']) throw new Conflict('request_conflict');
                return $existing;
            }
            if ($this->em->getRDBRepository('WhatsAppCampaign')->where([
                'chatwootAccountId' => $account->getId(), 'managedState' => self::ACTIVE_PREPARATION,
            ])->findOne()) throw new Conflict('preparation_already_active');
            $composition = $data['composition'] ?? [];
            if (($composition['mode'] ?? '') !== $this->access->mode($ctx['inbox'])) throw new BadRequest('invalid_mode');
            ManagedCampaignPayload::pacing($composition, $ctx['inbox']->get('channelType'));
            $media = $data['media'] ?? null;
            if ($media && (($media['bytes'] ?? 0) < 1 || $media['bytes'] > 16777216 ||
                ($media['key'] ?? '') !== (string) $data['operation_id'] || $composition['mode'] !== 'FreeText')) throw new BadRequest('invalid_media');
            $campaign = $this->em->createEntity('WhatsAppCampaign', [
                'name' => 'Chat bulk ' . $data['operation_id'], 'status' => 'Draft', 'managedByChatwoot' => true,
                'managedRequestKey' => $key, 'managedFingerprint' => $data['fingerprint'], 'managedOperationId' => $data['operation_id'],
                'managedPlatformId' => $ctx['platform']->getId(), 'managedUserId' => $ctx['user']->getId(), 'managedActorId' => $data['actor_id'],
                'chatwootAccountId' => $account->getId(), 'chatwootInboxId' => $ctx['inbox']->getId(),
                'tenantId' => $account->get('tenantId'), 'createdById' => $ctx['user']->getId(),
                'assignedUserId' => $ctx['user']->getId(), 'teamsIds' => $account->get('teamsIds') ?? [],
                'channelType' => $ctx['inbox']->get('channelType'), 'messageMode' => $composition['mode'],
                'managedState' => 'Importing', 'managedExpectedCount' => $data['expected_count'],
                'managedSelectionDigest' => $data['selection_digest'], 'managedComposition' => (object) $composition,
                'managedMedia' => $media ? (object) $media : null, 'managedBytes' => 0,
                'managedDigest' => $data['fingerprint'], 'managedProgressAt' => date('Y-m-d H:i:s'),
                'continuousEnrollment' => false, 'createOpportunity' => false,
            ], self::OPTIONS);
            $this->reserve($campaign, strlen(json_encode($composition)) + (int) ($media['bytes'] ?? 0));
            return $campaign;
        });
        return $this->summary($campaign);
    }

    private function append(Entity $campaign, array $data): array
    {
        return $this->em->getTransactionManager()->run(function () use ($campaign, $data): array {
            $campaign = $this->lock($campaign->getId());
            $ordinal = $data['ordinal'] ?? null;
            $records = $data['records'] ?? null;
            if (!is_int($ordinal) || $ordinal < 0 || !is_array($records) || !array_is_list($records) || count($records) < 1 || count($records) > 500) {
                throw new BadRequest('invalid_chunk');
            }
            // The hash covers the received records, independent of PHP/Ruby JSON escaping.
            $fingerprint = hash('sha256', json_encode($records, JSON_THROW_ON_ERROR));
            $existing = $this->em->getRDBRepository('WhatsAppCampaignImportChunk')->where(['campaignId' => $campaign->getId(), 'ordinal' => $ordinal])->findOne();
            if ($existing) {
                if ($existing->get('fingerprint') !== $fingerprint) throw new Conflict('chunk_conflict');
                return $this->summary($campaign);
            }
            if ($campaign->get('managedState') !== 'Importing') throw new Conflict('sealed_import');
            $repo = $this->em->getRDBRepository('WhatsAppCampaignImportChunk');
            if ($repo->where(['campaignId' => $campaign->getId()])->count() !== $ordinal) throw new Conflict('invalid_ordinal');
            $previous = $repo->where(['campaignId' => $campaign->getId()])->order('ordinal', 'DESC')->findOne();
            $last = (int) $previous?->get('lastConversationId');
            $sources = $this->em->getCollectionFactory()->create('WhatsAppCampaignSource');
            $now = date('Y-m-d H:i:s');
            foreach ($records as $record) {
                if (!is_int($record['conversation_id'] ?? null) || $record['conversation_id'] <= $last) throw new BadRequest('invalid_selection_order');
                $last = $record['conversation_id'];
                $source = $this->em->getNewEntity('WhatsAppCampaignSource');
                $source->set(['id' => Util::generateId(), 'campaignId' => $campaign->getId(), 'createdAt' => $now,
                    'conversationId' => $last, 'phoneNumber' => $record['phone_number'] ?? null, 'record' => (object) $record]);
                $sources[] = $source;
            }
            $count = (int) $campaign->get('managedImportedCount') + count($records);
            if ($count > (int) $campaign->get('managedExpectedCount')) throw new BadRequest('selection_count_mismatch');
            $campaign->set(['managedImportedCount' => $count, 'managedProgressAt' => date('Y-m-d H:i:s')]);
            $this->reserve($campaign, strlen(json_encode($records, JSON_THROW_ON_ERROR)));
            $this->em->getMapper()->massInsert($sources);
            $this->em->createEntity('WhatsAppCampaignImportChunk', ['campaignId' => $campaign->getId(), 'ordinal' => $ordinal,
                'fingerprint' => $fingerprint, 'lastConversationId' => $last]);
            return $this->summary($campaign);
        });
    }

    private function seal(Entity $campaign): array
    {
        return $this->em->getTransactionManager()->run(function () use ($campaign): array {
            $campaign = $this->lock($campaign->getId());
            if ($campaign->get('managedState') !== 'Importing') return $this->summary($campaign);
            if ($campaign->get('managedImportedCount') !== $campaign->get('managedExpectedCount')) throw new Conflict('incomplete_import');
            $hash = hash_init('sha256');
            $after = 0;
            do {
                $rows = $this->em->getRDBRepository('WhatsAppCampaignSource')->where(['campaignId' => $campaign->getId(), 'conversationId>' => $after])
                    ->order('conversationId')->limit(0, 500)->find();
                foreach ($rows as $row) {
                    $after = (int) $row->get('conversationId');
                    hash_update($hash, $after . "\n");
                }
            } while (count($rows) === 500);
            if (!hash_equals($campaign->get('managedSelectionDigest'), hash_final($hash))) throw new Conflict('selection_digest_mismatch');
            $campaign->set(['managedState' => 'Preparing', 'managedProgressAt' => date('Y-m-d H:i:s')]);
            $this->save($campaign);
            $this->schedule($campaign);
            return $this->summary($campaign);
        });
    }

    /** Account row serializes quota accounting across all retained campaigns. */
    public function reserve(Entity $campaign, int $bytes): void
    {
        $account = $this->em->getRDBRepository('ChatwootAccount')->where(['id' => $campaign->get('chatwootAccountId')])->forUpdate()->findOne();
        $used = $this->em->getRDBRepository('WhatsAppCampaign')->where(['chatwootAccountId' => $account->getId(), 'managedByChatwoot' => true])->sum('managedBytes');
        $budget = (float) ($account->get('managedCampaignBudgetBytes') ?: 1073741824);
        if ($used + $bytes > $budget) throw new BadRequest('snapshot_budget_exceeded');
        if ($used + $bytes > $budget * .8) $this->log->warning('Managed campaign snapshot budget above 80%.', ['accountId' => $account->getId()]);
        $campaign->set('managedBytes', (float) $campaign->get('managedBytes') + $bytes);
        $this->save($campaign);
    }

    public function schedule(Entity $campaign, int $delay = 0): void
    {
        $generation = (int) $campaign->get('managedGeneration') + 1;
        $job = $this->em->createEntity('Job', [
            'name' => 'Managed WhatsApp campaign', 'className' => 'Espo\\Modules\\Chatwoot\\Jobs\\ProcessManagedWhatsAppCampaign',
            'group' => 'managed-campaign-' . $campaign->getId(), 'executeTime' => date('Y-m-d H:i:s', time() + $delay),
            'data' => (object) ['campaignId' => $campaign->getId(), 'generation' => $generation], 'attempts' => 1,
        ]);
        $campaign->set(['managedJobId' => $job->getId(), 'managedGeneration' => $generation]);
        $this->save($campaign);
    }

    public function summary(Entity $campaign): array
    {
        $counts = [];
        $repo = $this->em->getRDBRepository('WhatsAppCampaignContact');
        foreach (['accepted', 'skipped', 'pending', 'attempting', 'retry'] as $status) {
            $counts[$status] = $repo->where(['whatsAppCampaignId' => $campaign->getId(), 'managedDispatch' => $status])->count();
        }
        foreach (['unknown', 'delivered', 'read', 'failed'] as $status) {
            $counts[$status] = $repo->where(['whatsAppCampaignId' => $campaign->getId(), 'managedDispatch' => 'accepted', 'managedDelivery' => $status])->count();
        }
        $inbox = $this->em->getEntityById('ChatwootInbox', (string) $campaign->get('chatwootInboxId'));
        $account = $this->em->getEntityById('ChatwootAccount', (string) $campaign->get('chatwootAccountId'));
        return ['id' => (int) $campaign->get('managedOperationId'), 'campaign_id' => $campaign->getId(),
            'sender_id' => $account ? $this->access->integrationSenderId($account) : null,
            'counts' => $counts, 'inbox' => $inbox?->get('name'),
            'state' => $campaign->get('managedState'), 'version' => (int) $campaign->get('managedVersion'), 'digest' => $campaign->get('managedDigest'),
            'selected' => (int) $campaign->get('managedExpectedCount'), 'imported' => (int) $campaign->get('managedImportedCount'),
            'prepared' => (int) $campaign->get('managedPreparedCount'), 'recipients' => (int) $campaign->get('managedEligibleCount'),
            'excluded' => (int) $campaign->get('managedExcludedCount'), 'error' => $campaign->get('managedError'),
            'composition' => $campaign->get('managedComposition'), 'media' => $campaign->get('managedMedia'),
            'ready_at' => $campaign->get('managedReadyAt'), 'purged' => (bool) $campaign->get('managedPurgedAt'),
            'created_at' => $campaign->get('createdAt'),
            'pacing' => ManagedCampaignPayload::pacing(ManagedCampaignPayload::array($campaign->get('managedComposition')), $campaign->get('channelType')),
            'cancellation' => $campaign->get('managedState') === 'Cancelled' ? 'acknowledged' : ($campaign->get('managedCancelRequested') ? 'requested' : null)];
    }

    public function lock(string $id): Entity
    {
        return $this->em->getRDBRepository('WhatsAppCampaign')->where(['id' => $id])->forUpdate()->findOne() ?? throw new NotFound();
    }

    public function save(Entity $campaign): void { $this->em->saveEntity($campaign, self::OPTIONS); }

    private function owned(array $ctx, array $data): Entity
    {
        $campaign = $this->em->getEntityById('WhatsAppCampaign', (string) ($data['campaign_id'] ?? ''));
        if (!$campaign || !$campaign->get('managedByChatwoot') || $campaign->get('managedPlatformId') !== $ctx['platform']->getId() ||
            $campaign->get('chatwootAccountId') !== $ctx['account']->getId() || $campaign->get('managedUserId') !== $ctx['user']->getId() ||
            (int) $campaign->get('managedActorId') !== (int) $data['actor_id'] ||
            (int) $campaign->get('managedOperationId') !== (int) ($data['operation_id'] ?? 0) ||
            ($ctx['inbox'] && $campaign->get('chatwootInboxId') !== $ctx['inbox']->getId())) throw new NotFound();
        $this->access->campaign($campaign, in_array($data['action'] ?? '', ['append', 'seal', 'confirm', 'retry'], true));
        return $campaign;
    }

    private function history(array $ctx, array $data): array
    {
        $where = ['managedByChatwoot' => true, 'chatwootAccountId' => $ctx['account']->getId(), 'managedUserId' => $ctx['user']->getId(),
            'managedPlatformId' => $ctx['platform']->getId(), 'managedActorId' => (int) $data['actor_id']];
        if (!empty($data['after'])) $where['id<'] = (string) $data['after'];
        $rows = iterator_to_array($this->em->getRDBRepository('WhatsAppCampaign')->where($where)->order('id', 'DESC')->limit(0, 101)->find(), false);
        $page = array_slice($rows, 0, 100);
        $visible = [];
        foreach ($page as $row) {
            try { $this->access->campaign($row, false); $visible[] = $this->summary($row); }
            catch (Forbidden) { continue; }
        }
        return ['payload' => $visible, 'next_cursor' => count($rows) > 100 ? end($page)->getId() : null];
    }

    private function cancel(Entity $campaign, array $ctx): array
    {
        $this->access->assertCancellation($campaign, $ctx);
        return $this->summary($this->factory->create(ManagedCampaignExecution::class)->cancel($campaign));
    }

    private function results(Entity $campaign, array $data, array $ctx): array
    {
        $sources = ($data['kind'] ?? '') === 'exclusions';
        if (!$sources) $this->payload->assertReadable(ManagedCampaignPayload::array($campaign->get('managedComposition')), $ctx);
        $where = $sources ? ['campaignId' => $campaign->getId(), 'reason!=' => null] : ['whatsAppCampaignId' => $campaign->getId()];
        if (!empty($data['after'])) $where['id>'] = (string) $data['after'];
        $rows = iterator_to_array($this->em->getRDBRepository($sources ? 'WhatsAppCampaignSource' : 'WhatsAppCampaignContact')
            ->where($where)->order('id')->limit(0, 101)->find(), false);
        $page = array_slice($rows, 0, 100);
        $visible = $sources ? $page : array_filter($page, function (Entity $row) use ($ctx): bool {
            $contact = $this->em->getEntityById('Contact', (string) $row->get('contactId'));
            return $contact && $row->get('chatwootAccountId') === $ctx['account']->getId() && $this->access->contact($contact, $ctx);
        });
        return ['version' => $campaign->get('managedVersion'), 'digest' => $campaign->get('managedDigest'),
            'payload' => array_map(fn ($row) => $sources ? [
                'id' => $row->getId(), 'conversation_id' => $row->get('conversationId'), 'reason' => $row->get('reason'),
            ] : [
                'id' => $row->getId(), 'phone_number' => $row->get('phoneNumber'), 'preview' => json_decode($row->get('managedPayload') ?: 'null', true),
                'dispatch' => $row->get('managedDispatch'), 'delivery' => $row->get('managedDelivery'), 'reason' => $row->get('managedError'),
                'message_id' => $row->get('chatwootMessageId'), 'conversation_id' => $row->get('chatwootConversationId'), 'replied_at' => $row->get('repliedAt'),
            ], array_values($visible)), 'next_cursor' => count($rows) > 100 ? end($page)->getId() : null];
    }
}
