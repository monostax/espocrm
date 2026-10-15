<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateParameters;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ManagedCampaignExecution
{
    public function __construct(
        private EntityManager $em, private ManagedCampaignAccess $access, private ManagedCampaignImport $imports,
        private ManagedCampaignBridge $bridge, private ManagedCampaignPayload $payload, private ManagedCampaignRecipients $recipients,
        private CampaignInboxGate $gate, private Log $log,
    ) {}

    public function confirm(Entity $campaign, array $review): Entity
    {
        if ((int) ($review['version'] ?? 0) !== (int) $campaign->get('managedVersion') ||
            !hash_equals($campaign->get('managedDigest') ?? '', (string) ($review['digest'] ?? ''))) throw new Conflict('review_changed');
        if ($campaign->get('managedConfirmedAt')) return $campaign;
        $ctx = $this->access->campaign($campaign);
        $this->templateUnchanged($campaign, $ctx);
        if (($this->bridge->call($campaign, 'authorize')['authorized'] ?? false) !== true) throw new Forbidden('Chat authorization unavailable.');
        return $this->em->getTransactionManager()->run(function () use ($campaign): Entity {
            $campaign = $this->imports->lock($campaign->getId());
            if ($campaign->get('managedConfirmedAt')) return $campaign;
            if ($campaign->get('managedState') !== 'Ready' || $campaign->get('managedCancelRequested') ||
                strtotime($campaign->get('managedReadyAt') . ' UTC') <= time() - 86400) throw new Conflict('review_expired');
            $campaign->set(['managedState' => 'Sending', 'status' => 'Sending', 'managedConfirmedAt' => date('Y-m-d H:i:s'),
                'startedAt' => date('Y-m-d H:i:s'), 'managedProgressAt' => date('Y-m-d H:i:s'), 'totalRecipients' => $campaign->get('managedEligibleCount')]);
            $this->imports->save($campaign);
            $this->imports->schedule($campaign);
            return $campaign;
        });
    }

    /** May also be invoked by CRM's authorized abort action. */
    public function cancel(Entity $campaign): Entity
    {
        $campaign = $this->em->getTransactionManager()->run(function () use ($campaign): Entity {
            $campaign = $this->imports->lock($campaign->getId());
            if (in_array($campaign->get('managedState'), ['Completed', 'Completed with issues', 'Expired', 'Cancelled', 'Preparation failed'], true)) return $campaign;
            $campaign->set('managedCancelRequested', true);
            $this->imports->save($campaign);
            return $campaign;
        });
        if (!$campaign->get('managedCancelRequested')) return $campaign;
        $this->gate->exclusive($campaign->get('chatwootInboxId'), function () use (&$campaign): void {
            $campaign = $this->em->getTransactionManager()->run(function () use ($campaign): Entity {
                $current = $this->imports->lock($campaign->getId());
                $current->set(['managedState' => 'Cancelled', 'status' => 'Cancelled', 'managedTerminatedAt' => date('Y-m-d H:i:s')]);
                $this->imports->save($current);
                return $current;
            });
        });
        return $campaign;
    }

    public function retry(Entity $campaign): Entity
    {
        $this->access->campaign($campaign);
        return $this->em->getTransactionManager()->run(function () use ($campaign): Entity {
            $campaign = $this->imports->lock($campaign->getId());
            if ($campaign->get('managedState') !== 'Needs attention') return $campaign;
            if ($campaign->get('managedError') !== 'handoff_unavailable' || $campaign->get('managedCancelRequested')) throw new Conflict('fresh_preparation_required');
            // Only the outstanding ambiguous handoff is eligible; accepted provider failures are never reset.
            $row = $this->em->getRDBRepository('WhatsAppCampaignContact')->where([
                'whatsAppCampaignId' => $campaign->getId(), 'managedDispatch' => ['attempting', 'retry'],
            ])->order('id')->findOne();
            if ($row) {
                $row->set(['retryCount' => 0, 'managedRetryAt' => null]);
                $this->em->saveEntity($row);
            }
            $campaign->set(['managedState' => 'Sending', 'managedError' => null]);
            $this->imports->schedule($campaign);
            return $campaign;
        });
    }

    public function process(Entity $campaign): void
    {
        if ($campaign->get('managedCancelRequested')) { $this->cancel($campaign); return; }
        $inbox = $this->em->getEntityById('ChatwootInbox', $campaign->get('chatwootInboxId'));
        if (!$inbox) { $this->attention($campaign, 'inbox_unavailable'); return; }
        $wait = $this->gate->attempt($campaign, $inbox, function () use ($campaign): void {
            $campaign = $this->em->getEntityById('WhatsAppCampaign', $campaign->getId());
            if ($campaign->get('managedState') !== 'Sending' || $campaign->get('managedCancelRequested')) return;
            $row = $this->em->getRDBRepository('WhatsAppCampaignContact')->where([
                'whatsAppCampaignId' => $campaign->getId(), 'managedDispatch' => ['pending', 'attempting', 'retry'],
            ])->order('id')->findOne();
            if (!$row) { $this->complete($campaign); return; }
            if ($row->get('managedRetryAt') && strtotime($row->get('managedRetryAt') . ' UTC') > time()) {
                $this->imports->schedule($campaign, max(1, strtotime($row->get('managedRetryAt') . ' UTC') - time()));
                return;
            }
            if (in_array($row->get('managedDispatch'), ['attempting', 'retry'], true)) {
                $receipt = $this->bridge->call($campaign, 'receipts', ['send_key' => $row->get('managedSendKey')]);
                if (!empty($receipt['payload'][0])) {
                    $this->accepted($campaign, $row, $receipt['payload'][0]);
                    $this->imports->schedule($campaign);
                    return;
                }
                if (!isset($receipt['payload'])) { $this->transient($campaign, $row); return; }
            }
            try {
                $ctx = $this->access->campaign($campaign);
                $this->templateUnchanged($campaign, $ctx);
                $authorization = $this->bridge->call($campaign, 'authorize');
                if (!empty($authorization['transient'])) { $this->transient($campaign, $row); return; }
                if (($authorization['authorized'] ?? false) !== true) throw new Forbidden();
            } catch (\Throwable) {
                $this->attention($campaign, 'permission_or_template_changed');
                return;
            }
            $contact = $this->em->getEntityById('Contact', $row->get('contactId'));
            $reason = $contact ? $this->recipients->exclusion($contact, $row->get('phoneNumber'), $ctx, $campaign) : 'contact_unavailable';
            if ($reason) {
                $row->set(['managedDispatch' => 'skipped', 'managedError' => $reason, 'status' => 'Blocked']);
                $this->em->saveEntity($row);
                $this->progress($campaign);
                $this->imports->schedule($campaign);
                return;
            }
            // Durable claim precedes I/O. Any crash reuses this exact key and bytes.
            $claimed = $this->em->getTransactionManager()->run(function () use ($campaign, $row): bool {
                $current = $this->imports->lock($campaign->getId());
                if ($current->get('managedState') !== 'Sending' || $current->get('managedCancelRequested')) return false;
                $row->set(['managedDispatch' => 'attempting', 'status' => 'Processing', 'claimedAt' => date('Y-m-d H:i:s')]);
                $this->em->saveEntity($row);
                return true;
            });
            if (!$claimed) return;
            $this->log->info('Managed campaign release.', ['campaignId' => $campaign->getId(), 'recipientId' => $row->getId(),
                'actorId' => $campaign->get('managedUserId'), 'stage' => 'handoff', 'attempt' => (int) $row->get('retryCount') + 1,
                'releaseTime' => date('Y-m-d H:i:s')]);
            $result = $this->bridge->call($campaign, 'accept', ['send_key' => $row->get('managedSendKey'),
                'fingerprint' => $row->get('managedFingerprint'), 'payload_json' => $row->get('managedPayload')]);
            if (!empty($result['transient'])) { $this->transient($campaign, $row); return; }
            if (($result['dispatch'] ?? '') === 'accepted' && !empty($result['message_id']) && !empty($result['conversation_id'])) {
                $this->accepted($campaign, $row, $result);
            } elseif (($result['dispatch'] ?? '') === 'skipped') {
                $row->set(['managedDispatch' => 'skipped', 'managedError' => 'source_ineligible', 'status' => 'Blocked']);
                $this->em->saveEntity($row);
                $this->progress($campaign);
            } else {
                $this->attention($campaign, 'handoff_rejected');
                return;
            }
            $this->imports->schedule($campaign);
        });
        if ($wait !== null) $this->imports->schedule($campaign, $wait);
    }

    private function templateUnchanged(Entity $campaign, array $ctx): void
    {
        $template = $this->payload->validate(ManagedCampaignPayload::array($campaign->get('managedComposition')), $ctx,
            $campaign->get('managedMedia') ? ManagedCampaignPayload::array($campaign->get('managedMedia')) : null);
        if ($template && TemplateParameters::signature($template) !== TemplateParameters::signature(ManagedCampaignPayload::array($campaign->get('managedTemplate')))) {
            throw new Conflict('template_changed');
        }
    }

    private function transient(Entity $campaign, Entity $row): void
    {
        $attempt = (int) $row->get('retryCount');
        if ($attempt >= 3) { $this->attention($campaign, 'handoff_unavailable'); return; }
        $delay = [60, 300, 900][$attempt];
        $row->set(['managedDispatch' => 'retry', 'status' => 'Retry', 'retryCount' => $attempt + 1,
            'managedRetryAt' => date('Y-m-d H:i:s', time() + $delay), 'managedError' => 'handoff_unavailable']);
        $this->em->saveEntity($row);
        $this->imports->schedule($campaign, $delay);
        $this->log->warning('Managed campaign handoff retry.', ['campaignId' => $campaign->getId(), 'recipientId' => $row->getId(), 'attempt' => $attempt + 1]);
    }

    public function accepted(Entity $campaign, Entity $row, array $result): void
    {
        $this->em->getTransactionManager()->run(function () use ($row, $result): void {
            $row = $this->em->getRDBRepository('WhatsAppCampaignContact')->where(['id' => $row->getId()])->forUpdate()->findOne();
            $row->set(['managedDispatch' => 'accepted', 'status' => 'Sent', 'chatwootMessageId' => (string) $result['message_id'],
                'chatwootConversationId' => (string) $result['conversation_id'], 'sentAt' => $row->get('sentAt') ?: date('Y-m-d H:i:s'),
                'managedError' => null, 'managedRetryAt' => null]);
            $this->applyDelivery($row, $result['delivery'] ?? 'unknown');
            $this->em->saveEntity($row);
        });
        $this->progress($campaign);
    }

    public function delivery(Entity $row, string $status): void
    {
        $this->em->getTransactionManager()->run(function () use ($row, $status): void {
            $fresh = $this->em->getRDBRepository('WhatsAppCampaignContact')->where(['id' => $row->getId()])->forUpdate()->findOne();
            if (!$fresh) return;
            $this->applyDelivery($fresh, $status);
            $this->em->saveEntity($fresh);
        });
    }

    private function applyDelivery(Entity $row, string $status): void
    {
        $rank = ['unknown' => 0, 'delivered' => 1, 'read' => 2, 'failed' => 3];
        if (!isset($rank[$status]) || $rank[$status] <= ($rank[$row->get('managedDelivery')] ?? 0)) return;
        $row->set('managedDelivery', $status);
        if ($status === 'delivered' || $status === 'read') $row->set('deliveredAt', $row->get('deliveredAt') ?: date('Y-m-d H:i:s'));
        if ($status === 'read') $row->set('readAt', $row->get('readAt') ?: date('Y-m-d H:i:s'));
        if ($status === 'failed') $row->set('failedAt', $row->get('failedAt') ?: date('Y-m-d H:i:s'));
    }

    private function progress(Entity $campaign): void
    {
        $campaign->set('managedProgressAt', date('Y-m-d H:i:s'));
        $this->imports->save($campaign);
    }

    private function attention(Entity $campaign, string $reason): void
    {
        $this->em->getTransactionManager()->run(function () use ($campaign, $reason): void {
            $current = $this->imports->lock($campaign->getId());
            if ($current->get('managedState') !== 'Sending' || $current->get('managedCancelRequested')) return;
            $current->set(['managedState' => 'Needs attention', 'managedError' => $reason]);
            $this->imports->save($current);
        });
        $this->log->warning('Managed campaign needs attention.', ['campaignId' => $campaign->getId(), 'actorId' => $campaign->get('managedUserId'), 'stage' => 'release', 'error' => $reason]);
    }

    private function complete(Entity $campaign): void
    {
        $issues = $this->em->getRDBRepository('WhatsAppCampaignContact')->where(['whatsAppCampaignId' => $campaign->getId(),
            'OR' => [['managedDispatch' => 'skipped'], ['managedDelivery' => 'failed']]])->count();
        $campaign->set(['managedState' => $issues ? 'Completed with issues' : 'Completed', 'status' => 'Completed',
            'managedTerminatedAt' => date('Y-m-d H:i:s')]);
        $this->progress($campaign);
    }
}
