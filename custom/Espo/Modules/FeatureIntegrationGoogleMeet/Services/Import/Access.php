<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Services\Import;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\Chatwoot\Tools\Activities\Access as Activities;
use Espo\Modules\FeatureRecordKnowledge\Services\Access as KnowledgeAccess;
use Espo\Modules\FeatureRecordKnowledge\Services\Tenancy;
use Espo\Modules\FeatureIntegrationGoogleMeet\Services\GoogleMeetCredentialHelper;

class Access
{
    public function __construct(private EntityManager $em, private Acl $acl, private User $user,
        private Activities $activities, private KnowledgeAccess $knowledge, private Tenancy $tenancy,
        private GoogleMeetCredentialHelper $credentials, private Config $config) {}

    public function enabled(): bool { return (bool) $this->config->get('meetingGoogleImportEnabled', false); }

    public function assertEnabled(): void
    {
        if (!$this->enabled()) throw new Forbidden('New Google Meet imports are disabled.');
    }

    public function meeting(int $accountId, string $id, bool $edit = false): Entity
    {
        $tenant = $this->activities->workspace($accountId);
        $meeting = $this->activities->record('Meeting', $id, $tenant);
        $this->knowledge->record('Meeting', $id, $edit ? 'edit' : 'read');
        // Unlike a list filter, an import must have unambiguous ownership.
        if ($this->tenancy->recordIds($meeting) !== [$tenant->getId()]) throw new Forbidden();
        return $meeting;
    }

    public function tenantId(int $accountId): string { return $this->activities->workspace($accountId)->getId(); }

    public function credential(int $accountId, string $id, bool $edit = false): Entity
    {
        $credential = $this->credentials->validateCredentialAccess($id);
        if ($this->tenancy->recordIds($credential) !== [$this->tenantId($accountId)]) throw new Forbidden();
        if (!$this->acl->checkScope('Credential', 'read') || !$this->acl->checkField('Credential', 'oAuthAccount') ||
            ($edit && !$this->acl->checkEntity($credential, 'edit'))) throw new Forbidden();
        $account = $this->em->getEntityById('OAuthAccount', $this->credentials->getOAuthAccountId($credential));
        if (!$account || $account->get('providerId') !== 'msx_gmeet_01') throw new NotFound('Connection unavailable.');
        // Credential is the authorization bridge. Never grant access via OAuthAccount alone.
        if ($edit && (!$this->acl->checkEntity($account, 'edit') ||
            $this->tenancy->recordIds($account) !== [$this->tenantId($accountId)])) throw new Forbidden();
        return $credential;
    }

    public function setupTeams(int $accountId): array
    {
        if (!$this->acl->checkScope('Credential', 'create') || !$this->acl->checkScope('OAuthAccount', 'create')) return [];
        foreach (['Credential' => ['name', 'teams', 'credentialType', 'oAuthAccount'], 'OAuthAccount' => ['name', 'teams', 'provider']] as $type => $fields) {
            foreach ($fields as $field) if (!$this->acl->checkField($type, $field, 'edit')) return [];
        }
        $query = $this->activities->referenceQuery('Team', $this->activities->workspace($accountId));
        if (!$this->user->isAdmin()) $query->where(['id' => $this->user->getTeamIdList()]);
        $result = [];
        foreach ($this->em->getRDBRepository('Team')->clone($query->build())->find() as $team) {
            $result[] = ['id' => $team->getId(), 'name' => $team->get('name')];
        }
        return $result;
    }

    public function recording(bool $edit = false): void
    {
        if (!$this->acl->checkScope('Attachment', $edit ? 'create' : 'read') ||
            !$this->acl->checkField('Meeting', 'googleMeetRecordings', $edit ? 'edit' : 'read')) throw new Forbidden();
    }

    public function text(?Entity $document, bool $edit = true): void
    {
        if (!$this->acl->checkScope('Document', $document ? ($edit ? 'edit' : 'read') : 'create')) throw new Forbidden();
        foreach (['body', 'bodyEditorState', 'bodyAuthoringMode', 'bodyFormat'] as $field) {
            if (!$this->acl->checkField('Document', $field, $edit ? 'edit' : 'read')) throw new Forbidden();
        }
        if ($document) $this->knowledge->document($document->getId(), $edit ? 'edit' : 'read');
    }
}
