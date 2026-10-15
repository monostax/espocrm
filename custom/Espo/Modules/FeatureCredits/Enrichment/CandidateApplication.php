<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\{BadRequest,Conflict,Forbidden,NotFound};
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\ORM\EntityManager;
use PDO;

/** One explicitly selected purchased candidate; all CRM writes and the receipt commit together. */
final class CandidateApplication
{
    public function __construct(private EntityManager $em,private Access $access,private Purchases $purchases,
        private ApplicationRecords $records,private Clock $clock) {}

    public function apply(ApplicationInput $input): array
    {
        $this->records->assertHuman(); $actor = $this->access->actorId();
        $pdo = $this->em->getPDO();
        if ($pdo->inTransaction()) throw new \LogicException('Candidate application must own its transaction.');
        // Use the CRM transaction manager, so nested record/relation saves share this transaction.
        $result = $this->em->getTransactionManager()->run(function () use ($input,$actor,$pdo): array|Conflict {
            $data = $input->data; $tenant = $data['tenantId']; $id = $data['purchaseId'];
            // Same actor -> tenant order as the existing manual Relations::submit service.
            if (!$this->em->getRDBRepository('User')->select(['id'])->where(['id' => $actor])->forUpdate()->findOne()) {
                throw new Forbidden('Applying user is unavailable.');
            }
            $q = $pdo->prepare('SELECT id FROM tenant WHERE id=? AND deleted=FALSE FOR UPDATE'); $q->execute([$tenant]);
            if (!$q->fetchColumn()) throw new NotFound('Unknown tenant.');
            $selected = $this->purchases->readLocked($pdo,$tenant,$id);
            $quote = new QuoteInput((object) $selected['terms']['request']);
            if (!in_array($quote->data['products'],[['employees'],['socios']],true) || !$selected['candidateGroup']) {
                throw new BadRequest('A purchased Finder candidate is required.');
            }
            $account = $quote->data['targetId'];
            $q = $pdo->prepare('SELECT id FROM account WHERE id=? AND tenant_id=? AND deleted=FALSE FOR UPDATE');
            $q->execute([$account,$tenant]); if (!$q->fetchColumn()) throw new NotFound('Account is unavailable.');
            $target = $this->access->target($quote);
            $permissions = $this->access->discoveryPermissions($quote,$target);
            $q = $pdo->prepare('SELECT * FROM enrichment_purchase WHERE tenant_id=? AND candidate_group=? ORDER BY purchased_at,id');
            $q->execute([$tenant,$selected['candidateGroup']]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            $facts = []; $roles = []; $mapped = null; $saved = null; $sources = [];
            foreach ($rows as $row) {
                if ($row['deleted']) throw new Conflict('Purchased candidate history is unavailable.');
                $purchase = $this->purchases->readLocked($pdo,$tenant,$row['id']);
                if ($purchase['erased']) throw new Conflict('Purchased candidate data was erased.');
                if ($purchase['conflict'] !== null) throw new Conflict('Candidate identity is blocked.');
                foreach ($purchase['values'] as $field => $value) {
                    if (in_array($field,['cpf','linkedinUrl','dateOfBirth'],true) && !($permissions['candidateRead'][$field] ?? false)) {
                        throw new Forbidden('Candidate identity field access required for application.');
                    }
                    if ($field === 'role') { $roles[$value] = true; continue; }
                    if (in_array($field,['cpf','linkedinUrl'],true) && isset($facts[$field]) && !CandidateProjection::equivalent($facts[$field],$value)) {
                        return CandidateConflicts::blockLocked($pdo,$tenant,[$selected['candidateGroup']],$this->clock->now(),'identity_contradiction');
                    }
                    // Later purchased supplements may update descriptive fields.
                    $facts[$field] = $value;
                }
                $sources[] = $row['id'];
                if ($row['applied_contact_id'] !== null) {
                    if ($mapped !== null && $mapped !== $row['applied_contact_id']) {
                        throw new Conflict('Candidate application mappings disagree.');
                    }
                    $mapped = $row['applied_contact_id'];
                }
                if ($row['id'] === $id) $saved = $row;
            }
            $hash = Quotes::hash($data);
            try {
                $contact = $this->records->resolve($tenant,$facts,$mapped);
            } catch (ApplicationIdentityConflict) {
                return CandidateConflicts::blockLocked($pdo,$tenant,[$selected['candidateGroup']],$this->clock->now(),'identity_contradiction');
            }
            if (isset($data['contactId'])) {
                if ($contact !== null && $contact !== $data['contactId']) {
                    throw new Conflict('Selected Contact differs from the candidate identity or previous application.');
                }
                try {
                    $contact = $this->records->resolve($tenant,$facts,$data['contactId']);
                } catch (ApplicationIdentityConflict) {
                    // A mistaken manual selection must not permanently block the purchased candidate.
                    throw new Conflict('Selected Contact is unavailable or has a conflicting identity.');
                }
            }
            if ($saved['application_hash'] !== null) {
                $receipt = json_decode($saved['application_receipt'],true,32,JSON_THROW_ON_ERROR);
                if ($contact !== ($receipt['contactId'] ?? null) || ($receipt['purchaseId'] ?? null) !== $id ||
                    ($receipt['accountId'] ?? null) !== $account || ($receipt['tenantId'] ?? null) !== $tenant) {
                    throw new Conflict('Application receipt binding differs.');
                }
                return $receipt;
            }
            $relationships = self::relationships($quote->data['products'][0],$facts,array_keys($roles));
            if ($contact === null) {
                if (!$data['createIfUnmatched']) throw new BadRequest('No matching Contact; enable contact creation and complete the required fields.');
                $contact = $this->records->create($tenant,$facts,$data['contactData']);
            } else {
                $this->records->update($tenant,$contact,$facts,$data['contactData']);
            }
            $this->records->link($tenant,$contact,$account);
            $relations = $this->records->relationships($tenant,$contact,$account,$relationships,$id);
            $receipt = ['purchaseId' => $id,'tenantId' => $tenant,'accountId' => $account,'contactId' => $contact,
                'relationIds' => $relations,'sourcePurchaseIds' => $sources,'actorId' => $actor,'appliedAt' => $this->clock->now()];
            $pdo->prepare('UPDATE enrichment_purchase SET application_hash=?,application_receipt=?,applied_contact_id=?,applied_identity_hash=? WHERE id=? AND tenant_id=?')
                ->execute([$hash,json_encode($receipt,JSON_THROW_ON_ERROR),$contact,Quotes::hash($this->records->identity($tenant,$contact)),$id,$tenant]);
            return $receipt;
        });
        if ($result instanceof Conflict) throw $result;
        return $result;
    }

    /** Preserve supplier roles; recognized QSA labels additionally identify ownership or administration. */
    public static function relationships(string $product,array $facts,array $roles): array
    {
        if ($product === 'employees') return [['predicate' => 'works_at','qualifiers' => isset($facts['title']) ? ['role' => $facts['title']] : []]];
        $result = [];
        foreach ($roles as $role) {
            $role = trim($role);
            $predicates = match (mb_strtolower($role)) {
                'owner', 'sócio' => ['owns'],
                'administrator', 'administrador', 'conselheiro de administração', 'diretor' => ['administers'],
                'owner_administrator', 'sócio-administrador' => ['owns','administers'],
                default => ['part_of'],
            };
            foreach ($predicates as $predicate) {
                $qualifiers = ['role' => $role];
                foreach (['since','until'] as $field) if (isset($facts[$field])) $qualifiers[$field] = $facts[$field];
                if ($predicate === 'owns' && isset($facts['ownershipPercentage'])) $qualifiers['ownershipPercentage'] = (float) $facts['ownershipPercentage'];
                $key = $predicate === 'part_of' ? $predicate . ':' . mb_strtolower($role) : $predicate;
                $result[$key] = ['predicate' => $predicate,'qualifiers' => $qualifiers];
            }
        }
        if (!$result) throw new Conflict('Purchased relationship is missing.');
        ksort($result); return array_values($result);
    }
}
