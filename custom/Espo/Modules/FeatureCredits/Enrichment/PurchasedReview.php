<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use PDO;

/** Free, current-actor review of one immutable purchased contribution, never hidden acquisitions. */
final class PurchasedReview
{
    public function __construct(private ReviewLock $lock,private Access $access,private Purchases $purchases,
        private ApplicationRecords $records) {}

    public function read(string $tenant,string $id): array
    {
        GrantInput::identity($tenant); GrantInput::identity($id);
        return $this->lock->run($tenant,fn (PDO $pdo): array => $this->readLocked($pdo,$tenant,$id));
    }

    public function readLocked(PDO $pdo,string $tenant,string $id): array
    {
            $saved = $this->purchases->readLocked($pdo,$tenant,$id);
            $input = new QuoteInput((object) $saved['terms']['request']);
            $target = $this->access->target($input);
            $permissions = $this->access->discoveryPermissions($input,$target);
            $values = $saved['values']; $redacted = [];
            foreach (['cpf','linkedinUrl'] as $field) {
                if (array_key_exists($field,$values) && !($permissions['candidateRead'][$field] ?? false)) {
                    unset($values[$field]); $redacted[] = $field;
                }
            }
            $identityChanged = Quotes::hash($this->access->discoveryIdentity($input,$target)) !== Quotes::hash($saved['terms']['identity']);
            $finder = in_array($input->data['products'],[['employees'],['socios']],true) && $saved['candidateGroup'] !== null;
            $human = $permissions['application']['actorIsHuman'] ?? false;
            $write = ($permissions['application']['accountLinkWriteAllowed'] ?? false) &&
                (($permissions['application']['contactCreateScopeAllowed'] ?? false) || ($permissions['application']['contactEditScopeAllowed'] ?? false)) && !$redacted;
            $rdap = $input->data['products'] === ['cnpj'];
            if ($rdap) {
                $permissions['application'] = $this->access->fieldApplicationPermissions($target,['cnpj']);
                $human = $permissions['application']['actorIsHuman'];
                $write = $permissions['application']['recordEditAllowed'] && in_array('cnpj',$permissions['application']['editableFields'],true);
            }
            return ['receipt' => $saved['receipt'],'values' => (object) $values,'redactedFields' => $redacted,'erased' => $saved['erased'],
                'provenance' => ['actorId' => $saved['terms']['actorId'],'targetType' => $input->data['targetType'],
                    'targetId' => $input->data['targetId'],'requestedProducts' => $input->data['products'],
                    'waterfall' => $input->data['waterfall'],'refresh' => $input->data['refresh'],
                    'delivery' => $saved['delivery'],'acquisitionQuoteId' => $saved['acquisitionQuoteId'],
                    'qualification' => $rdap ? 'domain_registrant_cnpj' : 'supplier_candidate'],
                'candidateGroup' => $saved['candidateGroup'],'kind' => $saved['kind'],
                'conflict' => $saved['conflict'],'identityChanged' => $identityChanged,
                // Availability is preflight only; explicit application rechecks every concrete write.
                'application' => [...$saved['applicationStatus'],'available' => !$saved['applicationStatus']['applied'] && ($finder || $rdap) && $human && $write && !$identityChanged && !$saved['erased'] && $saved['conflict'] === null,
                    'recheckRequired' => true,'permissions' => $permissions['application'],
                    'creationForm' => $finder && $human && $write ? $this->records->creationForm($tenant) : null,
                    'reason' => $saved['erased'] ? 'data_erased' : ($saved['conflict'] !== null ? 'identity_conflict' : ($identityChanged ? 'company_identity_changed' :
                        (!$finder && !$rdap ? 'application_not_implemented' : (!$human ? 'human_required' : (!$write ? 'application_permission_required' : null)))))]];
    }
}
