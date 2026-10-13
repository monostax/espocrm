<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\BrazilianIdentifier;
use Espo\Modules\Global\Tools\LinkedInProfile;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Purchase/read access is separate from field application/edit access. */
class Access
{
    public function __construct(private User $user, private Acl $acl,
        private UserTenantResolver $tenants, private EntityManager $entityManager) {}

    public function actorId(): string
    {
        if (!$this->user->getId() || $this->user->isPortal() || $this->user->get('deleted')) {
            throw new Forbidden('Enrichment access required.');
        }
        return $this->user->getId();
    }

    public function target(QuoteInput $input): Entity
    {
        $this->actorId();
        $data = $input->data;
        $type = $data['targetType'];
        if ($this->acl->getPermissionLevel('useEnrichmentCredits') !== 'yes' ||
            !$this->tenants->canActForTenant($this->user, $data['tenantId']) ||
            !$this->acl->checkScope($type, 'read')) {
            throw new Forbidden('Enrichment access required.');
        }
        $tenant = $this->entityManager->getEntityById('Tenant', $data['tenantId']);
        $target = $this->entityManager->getEntityById($type, $data['targetId']);
        if (!$tenant || $tenant->get('deleted') || !$target || $target->get('deleted') ||
            $target->get('tenantId') !== $data['tenantId'] || !$this->acl->check($target, 'read')) {
            throw new Forbidden('Enrichment access required.');
        }
        if ($input->isDiscovery()) {
            $source = match ($data['products'][0]) { 'employees' => 'linkedinUrl', 'socios' => 'cnpj', 'cnpj' => 'website' };
            if (!$this->acl->checkField('Account', $source, 'read')) throw new Forbidden('Discovery identity access required.');
            if ($data['products'] === ['cnpj']) {
                if (!$this->acl->checkField('Account', 'cnpj', 'read')) throw new Forbidden('Discovery destination access required.');
            } else {
                if (!$this->acl->checkScope('Contact', 'read') || !$this->acl->checkField('Account', 'name', 'read')) {
                    throw new Forbidden('Finder candidate access required.');
                }
                foreach (['name', 'firstName', 'lastName', ...($data['products'] === ['employees'] ? ['title'] : [])] as $field) {
                    if (!$this->acl->checkField('Contact', $field, 'read')) throw new Forbidden('Finder candidate field access required.');
                }
            }
            return $target;
        }
        foreach ($data['products'] as $product) {
            foreach (self::destinationFields($product) as $field) {
                if (!$this->acl->checkField($type, $field, 'read')) throw new Forbidden('Enrichment destination access required.');
            }
        }
        return $target;
    }

    /** Discovery freezes only the relevant own company input, never unrelated contact data. */
    public function discoveryIdentity(QuoteInput $input, Entity $target): array
    {
        $field = match ($input->data['products'][0]) { 'employees' => 'linkedinUrl', 'socios' => 'cnpj', 'cnpj' => 'website' };
        if (!$this->acl->checkField('Account', $field, 'read')) return [];
        $value = $target->get($field);
        return [$field => match ($field) {
            'linkedinUrl' => LinkedInProfile::profile('Account', $value),
            'cnpj' => BrazilianIdentifier::cnpj($value),
            'website' => is_string($value) && trim($value) !== '' ? trim($value) : null,
        }];
    }

    /** Quote-time facts, not application authority; concrete candidate/field checks remain mandatory. */
    public function discoveryPermissions(QuoteInput $input, Entity $target): array
    {
        if ($input->data['products'] === ['cnpj']) {
            return ['application' => ['accountEditAllowed' => $this->acl->check($target, 'edit'),
                'cnpjWriteAllowed' => $this->acl->checkField('Account', 'cnpj', 'edit'),
                'recheckRequired' => true]];
        }
        $linkedinRead = $this->acl->checkField('Contact', 'channelIdentitiesData', 'read') &&
            $this->acl->checkScope('ContactChannelIdentity', 'read') &&
            $this->acl->checkField('ContactChannelIdentity', 'channelType', 'read') &&
            $this->acl->checkField('ContactChannelIdentity', 'sourceId', 'read');
        $socios = $input->data['products'] === ['socios'];
        return ['candidateRead' => ['cpf' => $socios && $this->acl->checkField('Contact', 'cpf', 'read'), 'linkedinUrl' => $linkedinRead],
            'application' => ['humanRequired' => true, 'actorIsHuman' => !$this->user->isApi(),
                'contactCreateScopeAllowed' => $this->acl->checkScope('Contact', 'create'),
                'contactEditScopeAllowed' => $this->acl->checkScope('Contact', 'edit'),
                'contactNameWriteAllowed' => $this->acl->checkField('Contact', 'firstName', 'edit') && $this->acl->checkField('Contact', 'lastName', 'edit'),
                'accountLinkWriteAllowed' => $this->acl->checkField('Contact', 'accounts', 'edit'),
                'cpfWriteAllowed' => $socios && $this->acl->checkField('Contact', 'cpf', 'edit'),
                'candidateChecksRequired' => true, 'mandatoryFieldsRequired' => true, 'recheckRequired' => true]];
    }

    /** Only the record's own readable inputs may reach supplier eligibility/adapters. */
    public function identity(Entity $target): array
    {
        $type = $target->getEntityType();
        $fields = $type === 'Contact' ? ['firstName', 'lastName', 'emailAddress', 'phoneNumber', 'cpf'] :
            ['name', 'website', 'emailAddress', 'phoneNumber', 'cnpj', 'linkedinUrl'];
        $result = [];
        foreach ($fields as $field) {
            if (!$this->acl->checkField($type, $field, 'read')) continue;
            $value = $target->get($field);
            $result[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
            if ($field === 'cpf') $result[$field] = BrazilianIdentifier::cpf($value);
            if ($field === 'cnpj') $result[$field] = BrazilianIdentifier::cnpj($value);
            if ($field === 'linkedinUrl') $result[$field] = LinkedInProfile::profile($type,$value);
        }
        if ($type === 'Contact') $result += $this->contactLinkedIn($target);
        return $result;
    }

    /** A readable, non-rejected identity owned by this exact Contact and tenant. */
    private function contactLinkedIn(Entity $target): array
    {
        if (!$target->hasId() || !$this->acl->checkField('Contact','channelIdentitiesData','read') ||
            !$this->acl->checkScope('ContactChannelIdentity','read') ||
            !$this->acl->checkField('ContactChannelIdentity','channelType','read') ||
            !$this->acl->checkField('ContactChannelIdentity','sourceId','read') ||
            !$this->acl->checkField('ContactChannelIdentity','isPrimary','read')) return [];
        $pdo = $this->entityManager->getPDO();
        $q = $pdo->prepare("SELECT id FROM contact_channel_identity WHERE contact_id=? AND tenant_id=? AND channel_type='linkedin'
            AND deleted=FALSE AND (ownership_status IS NULL OR ownership_status<>'rejected') ORDER BY id" . ($pdo->inTransaction() ? ' FOR UPDATE' : ''));
        $q->execute([$target->getId(),$target->get('tenantId')]);
        $all = []; $primary = [];
        foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $identity = $this->entityManager->getEntityById('ContactChannelIdentity',$id);
            if (!$identity || !$this->acl->check($identity,'read')) continue;
            $profile = LinkedInProfile::profile('Contact',$identity->get('sourceId'));
            $candidate = ['linkedinUrl' => $profile,'linkedinIdentityId' => $id];
            if ($identity->get('isPrimary')) $primary[] = $candidate;
            if ($profile !== null) $all[] = $candidate;
        }
        // Never arbitrarily pick between several profile owners/primaries.
        $selected = count($primary) === 1 ? $primary[0] : (count($primary) === 0 && count($all) === 1 ? $all[0] : null);
        return $selected !== null && $selected['linkedinUrl'] !== null ? $selected : [];
    }

    public static function destinationFields(string $product): array
    {
        return match ($product) {
            'email' => ['emailAddress'],
            'phone' => ['phoneNumber'],
            // Normalized company bundle excludes separately purchased email/phone.
            'company' => ['name', 'website', 'industry', 'description', 'billingAddress',
                'billingAddressStreet', 'billingAddressCity', 'billingAddressState', 'billingAddressCountry', 'billingAddressPostalCode'],
            default => throw new \InvalidArgumentException('Unsupported enrichment product.'),
        };
    }

    public function fieldApplicationPermissions(Entity $target,array $fields): array
    {
        $human = !$this->user->isApi() && !$this->user->isPortal() && !$this->user->isSystem();
        $editable = array_values(array_filter($fields,fn ($field) => $this->acl->checkField($target->getEntityType(),$field,'edit')));
        return ['actorIsHuman' => $human,'recordEditAllowed' => $this->acl->check($target,'edit'),
            'editableFields' => $editable,'recheckRequired' => true];
    }
}
