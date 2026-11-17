<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiSession\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateParams;
use Espo\Entities\User;
use Espo\Modules\Global\Classes\Utils\TenantRoleAuth;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * An AI agent's instructions (its system prompt), read and changed on a person's behalf from an AI chat.
 *
 * Reading follows the CRM ACL on the agent and its instructions field. Changing them is for tenant admins only (the
 * Tenant Admin role, acting for the agent's tenant): one edit changes every answer the agent gives, so the AI chat
 * does not extend the ordinary edit right that the agent's settings screen grants. The update itself still goes
 * through the record service, so edit and field access, validation and hooks apply as in that screen.
 */
class AgentInstructions
{
    public const KEEP = 30;
    private const LISTED = 10;
    private const MAX_CHARS = 200000;

    public function __construct(
        private EntityManager $em, private User $user, private Acl $acl, private ServiceContainer $records,
        private UserTenantResolver $tenants,
    ) {}

    public function read(string $id): object
    {
        $agent = $this->agent($id);
        $text = (string) $agent->get('aiPrompt');
        return (object) [
            'id' => $agent->getId(), 'name' => $agent->get('name'), 'text' => $text, 'hash' => self::hash($text),
            'canEdit' => $this->canEdit($agent), 'changes' => $this->canEdit($agent) ? $this->revisions($id) : [],
        ];
    }

    /** @param string $expectedHash hash of the text the change was prepared from; a different current text is a conflict */
    public function write(string $id, string $text, string $expectedHash): object
    {
        $agent = $this->editable($id);
        if (trim($text) === '') throw new BadRequest('Instructions cannot be empty.');
        if (mb_strlen($text) > self::MAX_CHARS) throw new BadRequest('Instructions are too long.');
        if (!hash_equals(self::hash((string) $agent->get('aiPrompt')), $expectedHash)) throw new Conflict('Instructions changed meanwhile.');
        return $this->save($agent, $text);
    }

    public function restore(string $id, string $revisionId): object
    {
        $agent = $this->editable($id);
        $revision = $revisionId !== '' ? $this->em->getEntityById('AiAgentInstructionsRevision', $revisionId) : null;
        if (!$revision || $revision->get('membershipId') !== $id) throw new NotFound();
        return $this->save($agent, (string) $revision->get('aiPrompt'));
    }

    private function save(Entity $agent, string $text): object
    {
        if ($text === (string) $agent->get('aiPrompt')) return (object) ['changed' => false, 'id' => $agent->getId(), 'hash' => self::hash($text)];
        $this->records->get('ChatwootAccountUserMembership')->update($agent->getId(), (object) ['aiPrompt' => $text], UpdateParams::create());
        return (object) ['changed' => true, 'id' => $agent->getId(), 'hash' => self::hash($text), 'revisionId' => $this->revisions($agent->getId())[0]->id ?? null];
    }

    /** @return list<object> newest first: when each change was saved and by whom; restoring one puts back the text from before it */
    private function revisions(string $id): array
    {
        $list = [];
        $rows = $this->em->getRDBRepository('AiAgentInstructionsRevision')->where(['membershipId' => $id])
            ->order('number', 'DESC')->limit(0, self::LISTED)->find();
        foreach ($rows as $row) {
            $author = $row->get('createdById') ? $this->em->getEntityById('User', $row->get('createdById')) : null;
            $list[] = (object) ['id' => $row->getId(), 'changedAt' => $row->get('createdAt'), 'changedByName' => $author?->get('name'),
                'length' => mb_strlen((string) $row->get('aiPrompt'))];
        }
        return $list;
    }

    private function agent(string $id): Entity
    {
        $agent = $id !== '' ? $this->em->getEntityById('ChatwootAccountUserMembership', $id) : null;
        if (!$agent || !$agent->get('isAI') || !$this->acl->checkEntityRead($agent)) throw new NotFound();
        if (!$this->acl->checkField('ChatwootAccountUserMembership', 'aiPrompt')) throw new Forbidden();
        return $agent;
    }

    private function editable(string $id): Entity
    {
        $agent = $this->agent($id);
        if (!$this->canEdit($agent)) throw new Forbidden('Only tenant admins can change an AI agent\'s instructions here.');
        return $agent;
    }

    private function canEdit(Entity $agent): bool
    {
        $account = $this->em->getEntityById('ChatwootAccount', (string) $agent->get('chatwootAccountId'));
        $tenantId = (string) $account?->get('tenantId');
        return $tenantId !== '' && $this->user->isActive() &&
            TenantRoleAuth::hasTenantAdminRole($this->user, $this->em) &&
            ($this->user->isAdmin() || $this->tenants->canActForTenant($this->user, $tenantId)) &&
            $this->acl->checkEntityEdit($agent) && $this->acl->checkField('ChatwootAccountUserMembership', 'aiPrompt', 'edit');
    }

    private static function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
