<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureSelfService;

use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\WorkspaceStarter;
use Espo\ORM\BaseEntity;
use PHPUnit\Framework\TestCase;

class WorkspaceStarterTest extends TestCase
{
    public function testTheOperationIdIsAStableUuidV4PerProvisioning(): void
    {
        $id = WorkspaceStarter::operationId('6aeac5bae05add4ea');

        // The pattern AiAgentProvisioning accepts.
        self::assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id);
        self::assertSame($id, WorkspaceStarter::operationId('6aeac5bae05add4ea'), 'A retry resumes the same reservation.');
        self::assertNotSame($id, WorkspaceStarter::operationId('6aeac5bae05add4eb'));

        foreach (range(1, 200) as $n) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
                WorkspaceStarter::operationId('p' . $n));
        }
    }

    public function testTheAgentIsNamedAfterTheWorkspaceWithinTheNameLimit(): void
    {
        self::assertSame('IA Clínica Teste', WorkspaceStarter::agentName('  Clínica Teste '));
        self::assertSame(100, mb_strlen(WorkspaceStarter::agentName(str_repeat('é', 150))));
    }

    public function testTheOwnerEntersOnceTheirMembershipExists(): void
    {
        foreach (['tenant', 'owner', 'chatwootAccount', 'ownerMembership'] as $step) {
            self::assertFalse(Provisioner::ownerCanEnter($this->entity('running', $step)), $step);
        }
        foreach (['salesFunnel', 'aiAgent', 'emDia', 'done'] as $step) {
            self::assertTrue(Provisioner::ownerCanEnter($this->entity('failed', $step)), $step);
        }
        self::assertTrue(Provisioner::ownerCanEnter($this->entity('completed', 'done')));
    }

    private function entity(string $status, string $step): BaseEntity
    {
        $entity = new BaseEntity('SelfServiceProvisioning', ['attributes' => [
            'status' => ['type' => 'varchar'],
            'step' => ['type' => 'varchar'],
        ]]);
        $entity->set(['status' => $status, 'step' => $step]);

        return $entity;
    }
}
