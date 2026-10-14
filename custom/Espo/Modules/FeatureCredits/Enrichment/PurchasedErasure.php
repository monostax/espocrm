<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Utils\Crypt;
use Espo\Modules\FeatureCredits\Accounting\{Amount,Clock,FixedSettlement};
use Espo\Modules\Global\Tools\{BrazilianIdentifier,LinkedInProfile};
use Espo\ORM\{Entity,EntityManager};
use PDO;

/** Called by normal CRM deletion/privacy hooks, in their transaction. Never deletes financial receipts. */
class PurchasedErasure
{
    public function __construct(private EntityManager $em,private Crypt $crypt,private FixedSettlement $settlement,private Clock $clock) {}

    public function beforeRemove(Entity $entity,array $options): void { $this->erase($entity); }

    public function beforeDataPrivacyErase(Entity $entity,array $options): void
    {
        if ($options['fieldList'] ?? []) $this->erase($entity);
    }

    public function erase(Entity $entity): void
    {
        $tenant = $entity->get('tenantId');
        if (!is_string($tenant) || !$entity->hasId()) return;
        $type = $entity->getEntityType();
        if (!in_array($type,['Contact','Account'],true)) return;
        $pdo = $this->em->getPDO();
        if (!$pdo->inTransaction()) throw new \LogicException('Purchased-data erasure must share the CRM transaction.');
        $q = $pdo->prepare('SELECT id FROM tenant WHERE id=? FOR UPDATE'); $q->execute([$tenant]);
        if (!$q->fetchColumn()) throw new Conflict('Erasure tenant is unavailable.');
        $now = $this->clock->now(); $id = $entity->getId();
        $q = $pdo->prepare('SELECT * FROM enrichment_quote WHERE tenant_id=? AND target_type=? AND target_id=? ORDER BY id FOR UPDATE');
        $q->execute([$tenant,$type,$id]); $direct = $q->fetchAll(PDO::FETCH_ASSOC);
        $quotes = array_fill_keys(array_column($direct,'id'),true); $directIds = $quotes; $groups = []; $cacheIds = [];
        if ($type === 'Contact') {
            $q = $pdo->prepare('SELECT DISTINCT candidate_group FROM enrichment_purchase WHERE tenant_id=? AND applied_contact_id=? AND candidate_group IS NOT NULL');
            $q->execute([$tenant,$id]); $groups = array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);
            // Also erase exact-identity acquisitions bought before the Contact was explicitly selected.
            $cpf = BrazilianIdentifier::cpf($entity->get('cpf')); $profiles = [];
            $q = $pdo->prepare("SELECT source_id FROM contact_channel_identity WHERE tenant_id=? AND contact_id=? AND channel_type='linkedin'");
            $q->execute([$tenant,$id]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $value) if ($profile = LinkedInProfile::profile('Contact',$value)) $profiles[$profile] = true;
            $q = $pdo->prepare('SELECT * FROM enrichment_candidate_cache WHERE tenant_id=? AND payload_ciphertext IS NOT NULL ORDER BY id');
            $q->execute([$tenant]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $cache) {
                $payload = json_decode($this->crypt->decrypt($cache['payload_ciphertext']),true,32,JSON_THROW_ON_ERROR);
                $values = $payload['values'] ?? [];
                if (($cpf !== null && ($values['cpf'] ?? null) === $cpf) || isset($profiles[$values['linkedinUrl'] ?? ''])) {
                    $cacheIds[$cache['id']] = true;
                }
            }
            $q = $pdo->prepare('SELECT id,candidate_cache_id,candidate_group,values_ciphertext,erased_at FROM enrichment_purchase WHERE tenant_id=?');
            $q->execute([$tenant]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $purchase) {
                $values = $purchase['erased_at'] !== null ? [] : json_decode($this->crypt->decrypt($purchase['values_ciphertext']),true,32,JSON_THROW_ON_ERROR);
                if (isset($cacheIds[$purchase['candidate_cache_id'] ?? '']) || ($cpf !== null && ($values['cpf'] ?? null) === $cpf) ||
                    isset($profiles[$values['linkedinUrl'] ?? ''])) {
                    if ($purchase['candidate_group'] !== null) $groups[$purchase['candidate_group']] = true;
                }
            }
        }
        $q = $pdo->prepare('SELECT * FROM enrichment_purchase WHERE tenant_id=? ORDER BY id'); $q->execute([$tenant]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $purchase) {
            if (!isset($directIds[$purchase['quote_id']]) && !isset($groups[$purchase['candidate_group'] ?? ''])) continue;
            $pdo->prepare("UPDATE enrichment_purchase SET values_ciphertext='',erased_at=COALESCE(erased_at,?) WHERE id=? AND tenant_id=?")
                ->execute([$now,$purchase['id'],$tenant]);
            if ($purchase['candidate_cache_id'] !== null) $cacheIds[$purchase['candidate_cache_id']] = true;
            $quotes[$purchase['quote_id']] = true;
        }
        // Do not let expansion of the affected quote set erase unrelated purchased people.
        $q = $pdo->prepare('SELECT id,quote_id FROM enrichment_candidate_cache WHERE tenant_id=?'); $q->execute([$tenant]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $cache) {
            if (!isset($directIds[$cache['quote_id']]) && !isset($cacheIds[$cache['id']]) && !isset($groups[$cache['id']])) continue;
            $pdo->prepare('UPDATE enrichment_candidate_cache SET payload_ciphertext=NULL WHERE id=? AND tenant_id=?')->execute([$cache['id'],$tenant]);
            $quotes[$cache['quote_id']] = true;
        }
        foreach ($direct as $row) {
            $terms = Quotes::decode($row); $terms['identity'] = [];
            $pdo->prepare('UPDATE enrichment_quote SET snapshot=?,erasure_hash=?,erased_at=COALESCE(erased_at,?) WHERE id=? AND tenant_id=?')
                ->execute([json_encode($terms,JSON_THROW_ON_ERROR),Quotes::hash($terms),$now,$row['id'],$tenant]);
        }
        foreach (array_keys($quotes) as $quote) {
            // Raw supplier envelopes may contain several people; scrub the envelope and stop its remaining work.
            $pdo->prepare("UPDATE enrichment_discovery_call SET result_ciphertext=NULL,request_ciphertext=NULL,
                state=CASE WHEN state='claimed' THEN 'waived' ELSE state END WHERE tenant_id=? AND quote_id=?")->execute([$tenant,$quote]);
            $pdo->prepare("UPDATE enrichment_attempt SET result_ciphertext=NULL,cache_ciphertext=NULL,subject_id=NULL,erased_at=COALESCE(erased_at,?),
                state=CASE WHEN purchased_at IS NULL THEN 'waived' ELSE state END WHERE tenant_id=? AND quote_id=?")
                ->execute([$now,$tenant,$quote]);
            $this->close($pdo,$tenant,$quote,$now);
        }
        if ($type === 'Contact') {
            $this->eraseVerifications($pdo,$tenant,$id,$now);
        }
    }

    private function eraseVerifications(PDO $pdo,string $tenant,string $contactId,string $now): void
    {
        $q = $pdo->prepare('SELECT * FROM phone_verification_operation WHERE tenant_id=? AND contact_id=? ORDER BY id FOR UPDATE');
        $q->execute([$tenant,$contactId]);
        $operations = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($operations as $op) {
            $open = in_array($op['state'],['admitted','claimed','awaiting'],true);
            if ($open) {
                $pdo->prepare("UPDATE phone_verification_operation
                    SET identity_ciphertext=NULL,evidence_ciphertext=NULL,supplier_task_ciphertext=NULL,erased_at=COALESCE(erased_at,?),
                        state='waived',outcome='cancelled',failure_reason='contact_erased',completed_at=?
                    WHERE id=? AND tenant_id=?")->execute([$now,$now,$op['id'],$tenant]);
            } else {
                $pdo->prepare("UPDATE phone_verification_operation
                    SET identity_ciphertext=NULL,evidence_ciphertext=NULL,supplier_task_ciphertext=NULL,erased_at=COALESCE(erased_at,?)
                    WHERE id=? AND tenant_id=?")->execute([$now,$op['id'],$tenant]);
            }
            if (!$open) continue;
            $q = $pdo->prepare('SELECT * FROM credit_usage WHERE id=? AND tenant_id=?');
            $q->execute([$op['usage_id'],$tenant]); $usage = $q->fetch(PDO::FETCH_ASSOC);
            if (!$usage || $usage['state'] !== 'admitted') continue;
            $q = $pdo->prepare('SELECT * FROM credit_reservation WHERE id=? AND tenant_id=? AND usage_id=?');
            $q->execute([$op['reservation_id'],$tenant,$usage['id']]); $hold = $q->fetch(PDO::FETCH_ASSOC);
            $q = $pdo->prepare('SELECT * FROM tenant_credit_balance WHERE tenant_id=? FOR UPDATE');
            $q->execute([$tenant]); $wallet = $q->fetch(PDO::FETCH_ASSOC);
            if (!$hold || !$wallet || $wallet['deleted']) throw new Conflict('Verification erasure financial binding is unavailable.');
            $this->settlement->settleLocked($pdo,$wallet,$now,$usage,$hold,Amount::fromString('0'));
        }
    }

    private function close(PDO $pdo,string $tenant,string $quote,string $now): void
    {
        $q = $pdo->prepare('SELECT u.* FROM credit_usage u JOIN enrichment_quote q ON q.usage_id=u.id AND q.tenant_id=u.tenant_id
            WHERE q.id=? AND q.tenant_id=?'); $q->execute([$quote,$tenant]); $usage = $q->fetch(PDO::FETCH_ASSOC);
        if (!$usage || $usage['state'] !== 'admitted') return;
        $q = $pdo->prepare('SELECT * FROM credit_reservation WHERE tenant_id=? AND usage_id=?'); $q->execute([$tenant,$usage['id']]); $hold = $q->fetch(PDO::FETCH_ASSOC);
        $q = $pdo->prepare('SELECT * FROM tenant_credit_balance WHERE tenant_id=? FOR UPDATE'); $q->execute([$tenant]); $wallet = $q->fetch(PDO::FETCH_ASSOC);
        if (!$hold || !$wallet || $wallet['deleted']) throw new Conflict('Erasure financial binding is unavailable.');
        $this->settlement->settleLocked($pdo,$wallet,$now,$usage,$hold,Amount::fromString('0'));
        $pdo->prepare("UPDATE enrichment_quote SET discovery_stop_reason='cancelled' WHERE tenant_id=? AND id=?")->execute([$tenant,$quote]);
    }
}
