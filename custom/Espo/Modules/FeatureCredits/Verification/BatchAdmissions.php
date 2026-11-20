<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

use Espo\Core\Exceptions\{Conflict, Forbidden, NotFound};
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Accounting\{Clock, RecordId};
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\ORM\EntityManager;
use PDO;

/** Advance bounded chunks on the CRM dispatcher; a lost worker replays the same per-item admission key. */
final class BatchAdmissions
{
    public function __construct(private EntityManager $em, private ActorScope $actors, private Clock $clock, private Log $log) {}

    public function run(): void
    {
        $pdo = $this->em->getPDO();
        $now = $this->clock->now();
        $q = $pdo->prepare("SELECT id FROM verification_batch WHERE state='running' AND deleted=FALSE AND next_at<=? ORDER BY next_at,id LIMIT 3");
        $q->execute([$now]);
        $until = microtime(true) + 4;
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (microtime(true) >= $until) break;
            $lease = RecordId::generate();
            $claim = $pdo->prepare("UPDATE verification_batch SET lease_token=?,next_at=? WHERE id=? AND state='running' AND next_at<=? AND deleted=FALSE");
            $claim->execute([$lease,Operations::after($now,60),$id,$now]);
            if (!$claim->rowCount()) continue;
            $load = $pdo->prepare('SELECT * FROM verification_batch WHERE id=? AND lease_token=?');
            $load->execute([$id,$lease]);
            if (!$row = $load->fetch(PDO::FETCH_ASSOC)) continue;
            $commands = json_decode($row['commands'],true,8,JSON_THROW_ON_ERROR);
            $outcomes = json_decode($row['outcomes'],true,8,JSON_THROW_ON_ERROR);
            $state = 'running'; $delay = 0;
            try {
                if ($row['created_at'] <= Operations::after($now,-86400)) {
                    $state = 'stopped';
                } else {
                    $this->actors->run($row['actor_id'], function ($make) use ($row,$commands,&$outcomes,&$state,&$delay,$until) {
                        $ops = $make(Operations::class);
                        for ($n=0; $n<6 && count($outcomes)<count($commands) && microtime(true)<$until; $n++) {
                            $i = count($outcomes);
                            $input = $commands[$i];
                            $kind = $input['kind']; unset($input['kind']);
                            try {
                                $receipt = $ops->start((object)($input + ['tenantId'=>$row['tenant_id'],'contactId'=>$row['contact_id'],
                                    'requestKey'=>'batch:'.$row['id'].':'.$i]),$kind,true);
                                $outcomes[] = ['operationId'=>$receipt['operation']['id']];
                            } catch (Conflict $e) {
                                $details = json_decode((string)$e->getBody(),true);
                                $reason = $details['reason'] ?? 'unavailable';
                                if ($reason === 'rate_limited') {
                                    $delay = max(1,min(60,(int)($details['retryAfterSeconds'] ?? 60)));
                                    break;
                                }
                                $outcomes[] = ['reason'=>$reason];
                                if ($reason === 'insufficient_credits') { $state = 'stopped'; break; }
                            } catch (NotFound|\InvalidArgumentException) {
                                $outcomes[] = ['reason'=>'source_unavailable'];
                            }
                        }
                    });
                }
            } catch (Forbidden) {
                $state = 'stopped';
            } catch (\Throwable) {
                $delay = 30;
                $this->log->error('Retrying bulk verification admission for batch=' . $row['id']);
            }
            if ($state === 'running' && count($outcomes) === count($commands)) $state = 'submitted';
            $pdo->prepare('UPDATE verification_batch SET outcomes=?,state=?,next_at=?,lease_token=NULL WHERE id=? AND lease_token=?')
                ->execute([json_encode($outcomes,JSON_THROW_ON_ERROR),$state,Operations::after($this->clock->now(),$delay),$row['id'],$lease]);
        }
    }
}
