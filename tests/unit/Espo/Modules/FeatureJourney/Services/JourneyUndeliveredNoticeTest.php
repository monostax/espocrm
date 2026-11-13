<?php

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyUndeliveredNotice;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\References;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Who a journey message didn't reach goes on the record's mural, with the
 * owner mentioned so they are notified.
 */
class JourneyUndeliveredNoticeTest extends TestCase
{
    public function testPostsOnTheRecordMentioningItsOwner(): void
    {
        $created = null;
        $owner = $this->entity('User', 'u1', ['name' => 'Manuella Moura', 'isActive' => true, 'type' => 'regular']);

        $em = $this->createStub(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn (string $type, string $id) => $type === 'User' && $id === 'u1' ? $owner : null);
        $em->method('createEntity')->willReturnCallback(function (string $type, array $values) use (&$created) {
            $created = ['entity' => $type] + $values;

            return $this->createStub(Entity::class);
        });

        $account = $this->entity('Account', 'acc1', ['name' => 'Elite', 'assignedUserId' => 'u1'], ['assignedUserId']);
        $context = new ActionContext(
            $account,
            $this->entity('JourneyRecord', 'r1', []),
            $this->entity('JourneyStage', 's1', ['name' => 'D+2']),
            $this->entity('Journey', 'j1', ['name' => 'Cadência - Prospecção Ativa (nova)']),
            'OnEnter',
            [],
            't1',
        );

        (new JourneyUndeliveredNotice($em, $this->createStub(Log::class)))->post(
            $context,
            'O email ' . JourneyUndeliveredNotice::code('cadencia_prospeccao_ativa__d0__a'),
            [
                'Cristina Larcon dos Santos' => 'sem valor em ' . JourneyUndeliveredNotice::code('{{customFields.prospeccao.empresa_artigo}}'),
                'Marcos Silva Santana' => 'sem e-mail',
            ],
            2,
        );

        $this->assertSame(['Note', 'Post', 'Account', 'acc1', true], [
            $created['entity'],
            $created['type'],
            $created['parentType'],
            $created['parentId'],
            $created['isInternal'],
        ]);
        $this->assertSame(
            "[@Manuella Moura](#crm-reference/v1/record/User/u1) **Cadência - Prospecção Ativa (nova) · D+2**: " .
            "O email `cadencia_prospeccao_ativa__d0__a` não chegou a 2 de 2:\n" .
            "- **Cristina Larcon dos Santos**: sem valor em `{{customFields.prospeccao.empresa_artigo}}`\n" .
            "- **Marcos Silva Santana**: sem e-mail",
            $created['post']
        );
        $this->assertSame(
            [['kind' => 'record', 'entityType' => 'User', 'recordId' => 'u1']],
            References::fromMarkdown($created['post'])
        );
    }

    public function testNothingIsPostedWhenEveryoneGotIt(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->never())->method('createEntity');
        $context = new ActionContext(
            $this->entity('Account', 'acc1', []),
            $this->entity('JourneyRecord', 'r1', []),
            $this->entity('JourneyStage', 's1', []),
            $this->entity('Journey', 'j1', []),
            'OnEnter',
            [],
            't1',
        );

        (new JourneyUndeliveredNotice($em, $this->createStub(Log::class)))->post($context, 'O WhatsApp', [], 2);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $attributes
     */
    private function entity(string $type, string $id, array $values, array $attributes = []): Entity
    {
        $entity = $this->createStub(Entity::class);
        $entity->method('getEntityType')->willReturn($type);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(fn (string $attr) => $values[$attr] ?? null);
        $entity->method('hasAttribute')->willReturnCallback(fn (string $attr) => in_array($attr, $attributes, true));

        return $entity;
    }
}
