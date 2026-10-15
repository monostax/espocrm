<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Modules\Chatwoot\Tools\WhatsAppChannel;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** MySQL connection lock spans the attempt; the next slot survives worker death. */
class CampaignInboxGate
{
    public function __construct(private EntityManager $em) {}

    /** null = callback ran; otherwise seconds until bounded follow-up work. */
    public function attempt(Entity $campaign, Entity $inbox, callable $send): ?int
    {
        return $this->exclusive($inbox->getId(), function () use ($campaign, $inbox, $send): ?int {
            $pdo = $this->em->getPDO();
            // Empty continuous campaigns must not monopolize an inbox forever.
            $first = $pdo->prepare("SELECT c.id FROM whats_app_campaign c WHERE c.deleted = 0 AND c.chatwoot_inbox_id = ?
                AND c.status = 'Sending' AND EXISTS (SELECT 1 FROM whats_app_campaign_contact r
                    WHERE r.whats_app_campaign_id = c.id AND r.deleted = 0 AND r.status IN ('Pending', 'Retry', 'Processing'))
                ORDER BY c.started_at, c.id LIMIT 1");
            $first->execute([$inbox->getId()]);
            $firstId = $first->fetchColumn();
            if ($firstId && $firstId !== $campaign->getId()) return 5;
            $stmt = $pdo->prepare('SELECT UNIX_TIMESTAMP(NOW(6)), campaign_next_release_at FROM chatwoot_inbox WHERE id = ? AND deleted = 0');
            $stmt->execute([$inbox->getId()]);
            [$now, $next] = $stmt->fetch(\PDO::FETCH_NUM);
            if ((float) $next > (float) $now) return max(1, (int) ceil($next - $now));
            [$min, $max] = WhatsAppChannel::sendDelayWindowMs($inbox->get('channelType'));
            $delay = random_int($min, $max) / 1000;
            // Persist before I/O, using DB time; never accumulate missed slots.
            $update = $pdo->prepare('UPDATE chatwoot_inbox SET campaign_next_release_at = UNIX_TIMESTAMP(NOW(6)) + ? WHERE id = ?');
            $update->execute([$delay, $inbox->getId()]);
            try {
                $send();
            } finally {
                // Also space from completion: variable preflight/network duration
                // cannot turn two reservations into a burst at the actual sender.
                $update->execute([$delay, $inbox->getId()]);
            }
            return null;
        });
    }

    public function exclusive(string $inboxId, callable $work): mixed
    {
        $pdo = $this->em->getPDO();
        $name = 'campaign-inbox:' . $inboxId;
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$name]);
        if ((int) $lock->fetchColumn() !== 1) return 1;
        try {
            return $work();
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$name]);
        }
    }
}
