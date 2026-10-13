<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Acl;
use Espo\Core\Exceptions\{Conflict,Forbidden,NotFound};
use Espo\Core\Record\ServiceContainer;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\{Clock,GrantInput};
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PDO;
use stdClass;

/** Explicit selected values plus compare-before-write; normal CRM validation and an atomic retry receipt. */
final class FieldApplication
{
    public function __construct(private EntityManager $em,private User $user,private Acl $acl,private Access $access,
        private FieldReview $fields,private Purchases $purchases,private ServiceContainer $records,private Clock $clock) {}

    public function apply(stdClass $input): array
    {
        $data = get_object_vars($input);
        if (array_diff(array_keys($data),['tenantId','id','source','fields','expected']) ||
            !is_string($data['tenantId'] ?? null) || !is_string($data['id'] ?? null) ||
            !in_array($data['source'] ?? null,['field','rdap'],true) ||
            !($data['fields'] ?? null) instanceof stdClass || !($data['expected'] ?? null) instanceof stdClass) {
            throw new InvalidArgumentException('Explicit purchased fields and expected current values are required.');
        }
        GrantInput::identity($data['tenantId']); GrantInput::identity($data['id']);
        $fields = get_object_vars($data['fields']); $expected = get_object_vars($data['expected']);
        if (!$fields || count($fields) > 10 || array_diff(array_keys($fields),array_keys($expected)) ||
            array_diff(array_keys($expected),array_keys($fields))) throw new InvalidArgumentException('Expected values must match selected fields.');
        foreach ($fields as $field => $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 10000 ||
                !(is_string($expected[$field]) || $expected[$field] === null)) throw new InvalidArgumentException('Scalar purchased values required.');
        }
        if ($this->user->isApi() || $this->user->isPortal() || $this->user->isSystem()) throw new Forbidden('Human application required.');
        $actor = $this->access->actorId(); $pdo = $this->em->getPDO();
        if ($pdo->inTransaction()) throw new \LogicException('Field application must own its transaction.');
        return $this->em->getTransactionManager()->run(function () use ($pdo,$data,$fields,$expected,$actor): array {
            $tenant = $data['tenantId']; $id = $data['id'];
            if (!$this->em->getRDBRepository('User')->select(['id'])->where(['id' => $actor])->forUpdate()->findOne()) throw new Forbidden();
            $q = $pdo->prepare('SELECT id FROM tenant WHERE id=? AND deleted=FALSE FOR UPDATE'); $q->execute([$tenant]);
            if (!$q->fetchColumn()) throw new NotFound('Unknown tenant.');
            $saved = $data['source'] === 'field' ? $this->fields->readLocked($pdo,$tenant,$id) : $this->purchases->readLocked($pdo,$tenant,$id);
            if ($saved['erased']) throw new Conflict('Purchased data was erased.');
            $input = new QuoteInput((object) $saved['terms']['request']); $product = $saved['receipt']['product'];
            if ($data['source'] === 'rdap' && ($product !== 'cnpj' || $saved['conflict'] !== null)) throw new Conflict('A conflict-free registrant CNPJ purchase is required.');
            $type = $input->data['targetType']; $targetId = $input->data['targetId'];
            $table = $type === 'Contact' ? 'contact' : 'account';
            $q = $pdo->prepare("SELECT id FROM $table WHERE id=? AND tenant_id=? AND deleted=FALSE FOR UPDATE"); $q->execute([$targetId,$tenant]);
            if (!$q->fetchColumn()) throw new NotFound('Application target is unavailable.');
            $target = $this->access->target($input);
            if (!$this->acl->check($target,'edit')) throw new Forbidden('Target edit access required.');
            $allowed = self::choices($product,$saved['values']);
            foreach ($fields as $field => $value) {
                if (!$this->acl->checkField($type,$field,'read') || !$this->acl->checkField($type,$field,'edit')) throw new Forbidden('Selected field access required.');
                if (!in_array($value,$allowed[$field] ?? [],true)) throw new InvalidArgumentException('Selected value is not part of this purchase.');
            }
            $table = $data['source'] === 'field' ? 'enrichment_attempt' : 'enrichment_purchase';
            $q = $pdo->prepare("SELECT application_hash,application_receipt,applied_identity_hash FROM $table WHERE tenant_id=? AND id=?");
            $q->execute([$tenant,$id]); $application = $q->fetch(PDO::FETCH_ASSOC); $hash = Quotes::hash($data);
            $identity = fn ($entity) => $input->isDiscovery() ? $this->access->discoveryIdentity($input,$entity) : $this->access->identity($entity);
            $identityMatches = fn () => $data['source'] === 'field' ? $this->fields->identityMatches($pdo,$saved,$identity($target)) :
                Quotes::hash($identity($target)) === Quotes::hash($saved['terms']['identity']);
            if ($application['application_hash'] !== null) {
                if (!hash_equals($application['application_hash'],$hash) ||
                    (!hash_equals($application['applied_identity_hash'] ?? '',Quotes::hash($identity($target))) &&
                        !($data['source'] === 'field' && $identityMatches()))) throw new Conflict('Application retry or target identity changed.');
                foreach ($fields as $field => $value) if ($target->get($field) !== $value) throw new Conflict('Applied field changed; replay cannot overwrite it.');
                $receipt = json_decode($application['application_receipt'],true,32,JSON_THROW_ON_ERROR);
                if (($receipt['id'] ?? null) !== $id || ($receipt['tenantId'] ?? null) !== $tenant ||
                    ($receipt['targetId'] ?? null) !== $targetId || ($receipt['source'] ?? null) !== $data['source']) throw new Conflict('Application receipt binding differs.');
                return $receipt;
            }
            if (!$identityMatches()) throw new Conflict('Record identity changed; application requires review.');
            foreach ($expected as $field => $value) if ($target->get($field) !== $value) throw new Conflict('A selected destination changed after review.');
            $this->records->get($type)->update($targetId,(object) $fields);
            $updated = $this->em->getEntityById($type,$targetId);
            foreach ($fields as $field => $value) if ($updated->get($field) !== $value) throw new Conflict('CRM normalization changed the selected value.');
            $receipt = ['id' => $id,'source' => $data['source'],'tenantId' => $tenant,'targetType' => $type,'targetId' => $targetId,
                'fields' => array_keys($fields),'actorId' => $actor,'appliedAt' => $this->clock->now()];
            $pdo->prepare("UPDATE $table SET application_hash=?,application_receipt=?,applied_identity_hash=? WHERE tenant_id=? AND id=?")
                ->execute([$hash,json_encode($receipt,JSON_THROW_ON_ERROR),Quotes::hash($identity($updated)),$tenant,$id]);
            return $receipt;
        });
    }

    public static function choices(string $product,array $values): array
    {
        if ($product === 'email' || $product === 'phone') return [$product === 'email' ? 'emailAddress' : 'phoneNumber' => $values];
        $allowed = $product === 'cnpj' ? ['cnpj'] : Access::destinationFields($product); $result = [];
        foreach ($allowed as $field) if (is_string($values[$field] ?? null)) $result[$field] = [$values[$field]];
        return $result;
    }
}
