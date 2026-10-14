<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Acl;
use Espo\Core\Exceptions\{BadRequest,Forbidden};
use Espo\Core\Record\{CreateParams,ServiceContainer};
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\FeatureRecordKnowledge\Services\Relations;
use Espo\Modules\Global\Tools\{BrazilianIdentifier,LinkedInProfile};
use Espo\ORM\EntityManager;
use PDO;

/** Existing human record/relation services perform all CRM writes and mandatory-field validation. */
class ApplicationRecords
{
    private const SCALAR_TYPES = ['varchar','text','enum','date','bool','int'];

    public function __construct(private EntityManager $em,private ServiceContainer $records,private Relations $relations,
        private User $user,private Acl $acl,private Metadata $metadata) {}

    public function assertHuman(): void
    {
        if (!$this->user->hasId() || $this->user->isApi() || $this->user->isPortal() || $this->user->get('deleted') ||
            $this->user->getId() === 'system') throw new Forbidden('A human CRM user must apply purchased candidates.');
    }

    /** Pre-write only; missing/deleted/incompatible mappings must never silently select another Contact. */
    public function resolve(string $tenant,array $facts,?string $mapped): ?string
    {
        $pdo = $this->em->getPDO(); $matches = [];
        if (isset($facts['cpf'])) {
            $this->field('cpf','read');
            $q = $pdo->prepare("SELECT id FROM contact WHERE tenant_id=? AND deleted=FALSE
                AND REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),' ','')=? ORDER BY id FOR UPDATE");
            $q->execute([$tenant,$facts['cpf']]); $matches = $q->fetchAll(PDO::FETCH_COLUMN);
        }
        if (isset($facts['linkedinUrl'])) {
            $this->linkedinRead();
            $q = $pdo->prepare("SELECT id,contact_id FROM contact_channel_identity WHERE tenant_id=? AND channel_type='linkedin'
                AND source_id=? AND deleted=FALSE AND (ownership_status IS NULL OR ownership_status<>'rejected')");
            $q->execute([$tenant,$facts['linkedinUrl']]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $identity = $this->em->getEntityById('ContactChannelIdentity',$row['id']);
                if (!$identity || !$this->acl->check($identity,'read')) throw new Forbidden('LinkedIn identity read access required.');
                $matches[] = $row['contact_id'];
            }
        }
        $matches = array_values(array_unique($matches)); sort($matches,SORT_STRING);
        if (count($matches) > 1 || ($mapped !== null && $matches && $matches !== [$mapped])) {
            throw new ApplicationIdentityConflict('Candidate identities do not resolve to one Contact.');
        }
        $id = $mapped ?? ($matches[0] ?? null);
        if ($id === null) return null;
        $identity = $this->identity($tenant,$id);
        if (isset($facts['cpf']) && $identity['cpf'] !== null && $facts['cpf'] !== $identity['cpf']) {
            throw new ApplicationIdentityConflict('Contact CPF conflicts with the candidate.');
        }
        if (isset($facts['linkedinUrl']) && $identity['linkedin'] && $identity['linkedin'] !== [$facts['linkedinUrl']]) {
            throw new ApplicationIdentityConflict('Contact LinkedIn conflicts with the candidate.');
        }
        $this->contactAccess($tenant,$id);
        return $id;
    }

    public function identity(string $tenant,string $id): array
    {
        $pdo = $this->em->getPDO();
        $q = $pdo->prepare('SELECT cpf FROM contact WHERE id=? AND tenant_id=? AND deleted=FALSE FOR UPDATE');
        $q->execute([$id,$tenant]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ApplicationIdentityConflict('Mapped Contact is unavailable.');
        $q = $pdo->prepare("SELECT source_id FROM contact_channel_identity WHERE tenant_id=? AND contact_id=? AND channel_type='linkedin'
            AND deleted=FALSE AND (ownership_status IS NULL OR ownership_status<>'rejected') ORDER BY id FOR UPDATE");
        $q->execute([$tenant,$id]); $profiles = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $profile = LinkedInProfile::profile('Contact',$value); if ($profile !== null) $profiles[] = $profile;
        }
        $profiles = array_values(array_unique($profiles)); sort($profiles,SORT_STRING);
        return ['cpf' => BrazilianIdentifier::cpf($row['cpf']),'linkedin' => $profiles];
    }

    public function create(string $tenant,array $facts,array $fields): string
    {
        if (!$this->acl->checkScope('Contact','create')) throw new Forbidden('Contact creation access required.');
        $forbidden = ['cpf','emailAddress','phoneNumber','channelIdentitiesData','tenantId','accountId','id','name'];
        foreach ($fields as $field => $value) {
            $definition = $this->metadata->get(['entityDefs','Contact','fields',$field]);
            $standard = in_array($field,['firstName','lastName','teamsIds'],true);
            if (in_array($field,$forbidden,true) || (!$standard && (!($definition['required'] ?? false) ||
                !in_array($definition['type'] ?? '',self::SCALAR_TYPES,true)))) {
                throw new BadRequest('Only names, teams and mandatory scalar Contact fields may be supplied.');
            }
            $this->field($field === 'teamsIds' ? 'teams' : $field,'edit');
        }
        // Do not split supplier names or invent required fields. Normal CRM validation owns the form.
        if (!is_string($fields['lastName'] ?? null) || trim($fields['lastName']) === '') throw new BadRequest('Contact lastName is required.');
        $data = [...$fields,'tenantId' => $tenant];
        $this->field('tenant','edit');
        if (isset($facts['cpf']) && $this->acl->checkField('Contact','cpf','edit')) $data['cpf'] = $facts['cpf'];
        if (isset($facts['linkedinUrl'])) {
            $this->field('channelIdentitiesData','edit');
            if (!$this->acl->checkScope('ContactChannelIdentity','create')) throw new Forbidden('LinkedIn identity creation access required.');
            foreach (['channelType','sourceId'] as $field) if (!$this->acl->checkField('ContactChannelIdentity',$field,'edit')) {
                throw new Forbidden('LinkedIn identity write access required.');
            }
            $data['channelIdentitiesData'] = [(object) ['channelType' => 'linkedin','sourceId' => $facts['linkedinUrl'],'isPrimary' => true]];
        }
        // Explicit createIfUnmatched is the human's new-record choice. Name heuristics are not identity;
        // normal field validation and CPF/channel uniqueness hooks still run.
        $id = $this->records->get('Contact')->create((object) $data,(new CreateParams())->withSkipDuplicateCheck())->getEntity()->getId();
        $this->contactAccess($tenant,$id);
        return $id;
    }

    /** Read-only form projection after purchased-source authorization; writes still use normal CRM validation. */
    public function creationForm(string $tenant): array
    {
        $available = $this->acl->checkScope('Contact','create') && $this->acl->checkField('Contact','tenant','edit');
        $fields = [];
        foreach ($this->metadata->get(['entityDefs','Contact','fields'],[]) as $name => $definition) {
            if (in_array($name,['name','tenant','teams'],true)) continue;
            $standard = in_array($name,['firstName','lastName'],true);
            $required = $name === 'lastName' || ($definition['required'] ?? false);
            if (!$standard && !$required) continue;
            $allowed = !in_array($name,['cpf','emailAddress','phoneNumber','channelIdentitiesData','account','id'],true) &&
                in_array($definition['type'] ?? '',self::SCALAR_TYPES,true) &&
                !($definition['readOnly'] ?? false) && $this->acl->checkField('Contact',$name,'edit');
            if (!$allowed) { if ($required) $available = false; continue; }
            $fields[] = ['name' => $name,'type' => $definition['type'],'required' => $required,
                'options' => $definition['type'] === 'enum' ? array_values($definition['options'] ?? []) : [],
                'maxLength' => $definition['maxLength'] ?? null];
        }
        $teams = [];
        $teamsAllowed = $this->acl->checkField('Contact','teams','edit') &&
            $this->acl->checkScope('Team','read') && $this->acl->checkField('Team','name','read');
        if ($teamsAllowed) {
            $owner = $this->em->getEntityById('Tenant',$tenant);
            if ($owner) {
                $ids = [$owner->get('baseUserTeamId')];
                foreach ($this->em->getRDBRepository('Tenant')->getRelation($owner,'otherUserTeams')->find() as $team) {
                    $ids[] = $team->getId();
                }
                foreach (array_unique(array_filter($ids)) as $id) {
                    $team = $this->em->getEntityById('Team',$id);
                    if ($team && !$team->get('deleted') && $this->acl->check($team,'read')) {
                        $teams[] = ['id' => $id,'name' => $team->get('name')];
                    }
                }
            }
        }
        $teamsRequired = (bool) $this->metadata->get(['entityDefs','Contact','fields','teams','required'],false);
        if ($teamsRequired && !$teams) $available = false;
        return ['available' => $available,'fields' => $fields,'teams' => $teams,
            'teamsAllowed' => $teamsAllowed,'teamsRequired' => $teamsRequired];
    }

    public function link(string $tenant,string $contact,string $account): void
    {
        $this->contactAccess($tenant,$contact); $this->field('accounts','edit');
        $row = $this->accountLink($contact,$account);
        if ($row && !$row['deleted']) {
            return; // Preserve primary Account and every manual compatibility role.
        }
        $this->records->get('Contact')->link($contact,'accounts',$account);
    }

    public function relationships(string $tenant,string $contact,string $account,array $relationships,string $key): array
    {
        $ids = [];
        foreach ($relationships as $index => $item) {
            $q = $this->em->getPDO()->prepare("SELECT id,status FROM record_relation WHERE tenant_id=? AND subject_type='Contact'
                AND subject_id=? AND object_type='Account' AND object_id=? AND predicate=? AND deleted=FALSE
                AND status IN ('confirmed','suggested') ORDER BY id FOR UPDATE");
            $q->execute([$tenant,$contact,$account,'builtin:' . $item['predicate']]);
            $reuse = null; $confirmed = null;
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['status'] === 'confirmed') $confirmed ??= $row['id'];
                $reuse = $row['id'];
            }
            $reuse = $confirmed ?? $reuse;
            if ($reuse !== null) {
                // Reuse existing relationship details instead of rejecting or replacing them.
                $ids[] = $this->relations->decide($reuse,'confirmed')['id'];
            } else {
                $ids[] = $this->relations->submit((object) ['tenantId' => $tenant,'subjectType' => 'Contact','subjectId' => $contact,
                    'objectType' => 'Account','objectId' => $account,'predicate' => $item['predicate'],
                    'qualifiers' => (object) $item['qualifiers'],'idempotencyKey' => 'enrichment:' . $key . ':' . $index],true)['id'];
            }
        }
        return $ids;
    }

    private function accountLink(string $contact,string $account): array|false
    {
        $q = $this->em->getPDO()->prepare('SELECT deleted FROM account_contact WHERE contact_id=? AND account_id=? FOR UPDATE');
        $q->execute([$contact,$account]); return $q->fetch(PDO::FETCH_ASSOC);
    }

    private function contactAccess(string $tenant,string $id): void
    {
        $record = $this->em->getEntityById('Contact',$id);
        if (!$record || $record->get('deleted') || $record->get('tenantId') !== $tenant ||
            !$this->acl->check($record,'read') || !$this->acl->check($record,'edit')) throw new Forbidden('Contact read/edit access required.');
    }

    private function field(string $field,string $action): void
    {
        if (!$this->acl->checkField('Contact',$field,$action)) throw new Forbidden('Contact field access required.');
    }

    private function linkedinRead(): void
    {
        $this->field('channelIdentitiesData','read');
        if (!$this->acl->checkScope('ContactChannelIdentity','read')) throw new Forbidden('LinkedIn identity read access required.');
        foreach (['channelType','sourceId'] as $field) if (!$this->acl->checkField('ContactChannelIdentity',$field,'read')) {
            throw new Forbidden('LinkedIn identity read access required.');
        }
    }
}
