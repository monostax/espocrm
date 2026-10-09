<?php
declare(strict_types=1);
namespace tests\unit\Espo\Modules\FeatureAiSession;

use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureAiSession\Hooks\AiSession\Validate;
use Espo\Modules\FeatureAiSession\Services\Access;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    private function session(): BaseEntity
    {
        $attributes = [];
        foreach (['id', 'name', 'assignedUserId', 'tenantId', 'chatwootAccountId', 'aiAgentMembershipId'] as $field) $attributes[$field] = ['type' => 'varchar'];
        $attributes['titleInitialized'] = ['type' => 'bool'];
        $session = new BaseEntity('AiSession', ['attributes' => $attributes]);
        $session->set(['name' => 'New chat', 'assignedUserId' => 'spoofed', 'tenantId' => 'spoofed', 'chatwootAccountId' => 'account', 'aiAgentMembershipId' => 'agent']);
        return $session;
    }

    private function hook(): Validate
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('owner');
        $account = new BaseEntity('ChatwootAccount', ['attributes' => ['tenantId' => ['type' => 'varchar']]]);
        $account->set('tenantId', 'tenant');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->with('ChatwootAccount', 'account')->willReturn($account);
        $access = $this->createMock(Access::class);
        $access->method('owner')->willReturn(true);
        $access->method('agent')->willReturn($account);
        return new Validate($user, $em, $access);
    }

    public function testOwnerAndTenantAreServerAssignedAndManualRenameIsRemembered(): void
    {
        $session = $this->session();
        $hook = $this->hook();
        $hook->beforeSave($session, SaveOptions::fromAssoc([]));
        self::assertSame('owner', $session->get('assignedUserId'));
        self::assertSame('tenant', $session->get('tenantId'));
        self::assertFalse($session->get('titleInitialized'));
        $session->setAsFetched();
        $session->set('name', 'My manual title');
        $hook->beforeSave($session, SaveOptions::fromAssoc([]));
        self::assertTrue($session->get('titleInitialized'));
        $session->setAsFetched();
        $session->set('aiAgentMembershipId', 'other-agent');
        $hook->beforeSave($session, SaveOptions::fromAssoc([]));
        self::assertSame('My manual title', $session->get('name'));
        self::assertTrue($session->get('titleInitialized'));
    }

    public function testOwnershipCannotBeReassigned(): void
    {
        $session = $this->session();
        $hook = $this->hook();
        $hook->beforeSave($session, SaveOptions::fromAssoc([]));
        $session->setAsFetched();
        $session->set('assignedUserId', 'someone-else');
        $this->expectException(Forbidden::class);
        $hook->beforeSave($session, SaveOptions::fromAssoc([]));
    }
}
