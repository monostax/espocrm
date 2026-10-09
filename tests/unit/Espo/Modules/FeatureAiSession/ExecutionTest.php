<?php
declare(strict_types=1);
namespace tests\unit\Espo\Modules\FeatureAiSession;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\FileStorage\Manager;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\FeatureAiSession\Services\Access;
use Espo\Modules\FeatureAiSession\Services\Execution;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

require_once __DIR__ . '/../Chatwoot/Support/EntityDouble.php';

class ExecutionTest extends TestCase
{
    public function testGrantUsesCapturedMembershipAndRevalidatesOwnerInsteadOfSessionSelection(): void
    {
        $defs = ['attributes' => ['data' => ['type' => 'jsonObject'], 'opportunityPostDeleted' => ['type' => 'bool']]];
        foreach (['id', 'type', 'parentType', 'parentId', 'createdById'] as $field) $defs['attributes'][$field] = ['type' => 'varchar'];
        $note = new Note('Note', $defs);
        $note->set(['id' => 'source', 'type' => 'Post', 'parentType' => 'AiSession', 'parentId' => 'session', 'createdById' => 'human',
            'data' => (object) ['aiSessionSubmission' => true, 'opportunityAiInitiatorUserId' => 'human',
                'opportunityAiMentionTargets' => [(object) ['aiAgentMembershipId' => 'captured', 'chatwootAccountCrmId' => 'account', 'crmTenantId' => 'tenant']]]]);
        $owner = $this->createMock(User::class);
        $actor = $this->createMock(User::class);
        $actor->method('getId')->willReturn('ai-user');
        $actor->method('isActive')->willReturn(true);
        $session = new EntityDouble(['id' => 'session', 'assignedUserId' => 'human', 'chatwootAccountId' => 'account', 'tenantId' => 'tenant', 'aiAgentMembershipId' => 'new-selection']);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type) => match ($type) {
            'AiSession' => $session, 'User' => $owner, 'ChatwootUser' => new EntityDouble(['assignedUserId' => 'ai-user']),
        });
        $acl = $this->createMock(AclManager::class);
        $acl->method('checkEntityRead')->willReturn(true);
        $acl->method('checkEntityStream')->willReturn(true);
        $acl->method('checkScope')->willReturn(true);
        $fieldsAllowed = true;
        $acl->method('checkField')->willReturnCallback(function () use (&$fieldsAllowed) { return $fieldsAllowed; });
        $revoked = false;
        $access = $this->createMock(Access::class);
        $access->method('agent')->with($owner, $session, 'captured')->willReturnCallback(function () use (&$revoked) {
            if ($revoked) throw new Forbidden();
            return new EntityDouble(['chatwootUserId' => 'identity']);
        });
        $execution = new Execution($em, $access, $acl, $this->createMock(Manager::class));
        self::assertTrue($execution->authorized($note, 'captured', $actor));
        self::assertFalse($execution->authorized($note, 'new-selection', $actor));
        $fieldsAllowed = false;
        self::assertFalse($execution->authorized($note, 'captured', $actor), 'Revoking field read access also revokes model context.');
        $fieldsAllowed = true;
        $revoked = true;
        self::assertFalse($execution->authorized($note, 'captured', $actor));
        $revoked = false;
        $note->set('createdById', 'other-human');
        self::assertFalse($execution->authorized($note, 'captured', $actor));
        $note->set('createdById', 'human');
        $data = $note->getData();
        unset($data->aiSessionSubmission);
        $note->setData($data);
        self::assertFalse($execution->authorized($note, 'captured', $actor), 'Imports and generic Note API posts are not execution grants.');
    }
}
