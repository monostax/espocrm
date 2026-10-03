<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureKnowledgeBaseEditor;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\SelectBuilder as RecordSelectBuilder;
use Espo\Core\Utils\Metadata;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityManager;
use Espo\ORM\EntityCollection;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\References;
use Espo\Modules\FeatureKnowledgeBaseEditor\Hooks\Common\EditorState;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\ReferenceIndex;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\EditorReferences;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\PromptPreparation;
use PHPUnit\Framework\TestCase;

class ReferencesTest extends TestCase
{
    private function state(array $refs): string
    {
        return json_encode(['root' => ['type' => 'root', 'children' => array_map(fn ($ref) =>
            ['type' => 'crm-mention', 'version' => 1, 'reference' => $ref], $refs)]]);
    }

    public function testStableIdentityRoundTripAndDeduplication(): void
    {
        $record = ['kind' => 'record', 'entityType' => 'Account', 'recordId' => 'acme'];
        $context = ['kind' => 'context', 'key' => 'primaryContact'];
        $this->assertSame($record, References::fromUrl(References::url($record)));
        $this->assertSame($context, References::fromUrl(References::url($context)));
        $refs = References::fromState($this->state([$record, $context, [...$record, 'label' => 'Renamed']]));
        $this->assertCount(2, $refs);
        $this->assertSame('acme', $refs[0]['recordId']);
        // Portable syntax accepts custom types; merged metadata validates availability.
        $this->assertSame('CustomProject', References::fromUrl('#crm-reference/v1/record/CustomProject/id')['entityType']);
        $this->assertNull(References::fromUrl('#crm-reference/v1/record/invalid-type/id'));
        $this->assertNull(References::fromUrl('#crm-reference/v1/record/User/../secret'));
        $this->assertNull(References::fromUrl('javascript:alert(1)'));
    }

    public function testMalformedMentionCannotEnterCanonicalState(): void
    {
        $this->expectException(BadRequest::class);
        References::fromState($this->state([['kind' => 'record', 'entityType' => 'User', 'recordId' => '<script>']]));
    }

    public function testProjectionOnlyWriteInvalidatesJsonBeforeIndexMaintenance(): void
    {
        $entity = new BaseEntity('ChatwootAccountUserMembership', ['attributes' => array_fill_keys(
            ['id', 'aiPrompt', 'aiPromptEditorState'], ['type' => 'text'])]);
        $entity->set(['id' => 'ai', 'aiPrompt' => '<p>old</p>', 'aiPromptEditorState' => $this->state([])]);
        $entity->setAsNotNew(); $entity->updateFetchedValues();
        $entity->set('aiPrompt', '<p>new API content</p>');
        $index = $this->createMock(ReferenceIndex::class);
        $index->expects($this->once())->method('replace')->with($entity);
        $hook = new EditorState($index);
        $hook->beforeSave($entity, []);
        $this->assertNull($entity->get('aiPromptEditorState'));
        $hook->afterSave($entity, []);
    }

    public function testDeniedScopeAndMentionPermissionNeverExposeCachedLabels(): void
    {
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(false);
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturn(true);
        $select = $this->createMock(SelectBuilderFactory::class);
        $select->expects($this->never())->method('create');
        $service = new EditorReferences($this->createMock(EntityManager::class), $select, $acl, $metadata);
        $results = $service->resolve([['kind' => 'record', 'entityType' => 'Contact', 'recordId' => 'secret', 'label' => 'Private name']]);
        $this->assertSame('Unavailable reference', $results[0]['label']);
        $this->assertFalse($results[0]['available']);
    }

    public function testMissingContextDoesNotGuessFromRelationships(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->never())->method('getEntityById');
        $select = $this->createMock(SelectBuilderFactory::class);
        $select->expects($this->never())->method('create');
        $service = new EditorReferences($em, $select, $this->createMock(Acl::class), $this->createMock(Metadata::class));
        foreach (array_keys(References::CONTEXT) as $key) {
            $this->assertNull($service->context(['kind' => 'context', 'key' => $key], []));
        }
    }

    private function entity(string $type, array $values): BaseEntity
    {
        $entity = new BaseEntity($type, ['attributes' => array_fill_keys(array_keys($values), ['type' => 'varchar'])]);
        $entity->set($values);
        return $entity;
    }

    private function queryFixture(string $type, array $entities): array
    {
        $builder = $this->createMock(RecordSelectBuilder::class);
        $builder->method('from')->with($type)->willReturnSelf();
        $builder->expects($this->once())->method('withStrictAccessControl')->willReturnSelf();
        $builder->method('buildQueryBuilder')->willReturn(SelectBuilder::create()->from($type));
        $select = $this->createMock(SelectBuilderFactory::class);
        $select->expects($this->once())->method('create')->willReturn($builder);
        $results = $this->createMock(RDBSelectBuilder::class);
        $results->method('find')->willReturn(new EntityCollection($entities));
        $results->method('findOne')->willReturn($entities[0] ?? null);
        $repo = $this->createMock(RDBRepository::class);
        $repo->method('clone')->willReturn($results);
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->with($type)->willReturn($repo);
        return [$em, $select];
    }

    public function testBatchResolutionRechecksRecordsAndUserMentionTeamPermission(): void
    {
        $entities = [
            $this->entity('User', ['id' => 'teammate', 'name' => 'New name', 'isActive' => true]),
            $this->entity('User', ['id' => 'outside', 'name' => 'Hidden', 'isActive' => true]),
        ];
        [$em, $select] = $this->queryFixture('User', $entities);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('checkField')->willReturn(true);
        $acl->method('checkEntityRead')->willReturn(true);
        $acl->method('getPermissionLevel')->with('mention')->willReturn('team');
        $acl->method('checkUserPermission')->willReturnCallback(fn ($id, $permission) => $id === 'teammate' && $permission === 'mention');
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturn(true);
        $metadata->method('getAll')->willReturn((object) [
            'scopes' => (object) ['User' => (object) ['entity' => true, 'recordKnowledge' => true]],
            'entityDefs' => (object) ['User' => (object) ['fields' => (object) ['name' => (object) []]]],
        ]);
        $service = new EditorReferences($em, $select, $acl, $metadata);
        $results = $service->resolve(array_map(fn ($id) => ['kind' => 'record', 'entityType' => 'User', 'recordId' => $id, 'label' => 'Cached'], ['teammate', 'outside', 'deleted']));
        $this->assertSame('New name', $results[0]['label']);
        $this->assertTrue($results[0]['available']);
        $this->assertSame('Unavailable reference', $results[1]['label']);
        $this->assertFalse($results[1]['available']);
        $this->assertFalse($results[2]['available']);
    }

    public function testRuntimeUsesResolvedIdentityAndExplicitUnresolvedContext(): void
    {
        $record = ['kind' => 'record', 'entityType' => 'Account', 'recordId' => 'acme', 'label' => 'Stale label'];
        $context = ['kind' => 'context', 'key' => 'primaryContact'];
        $membership = $this->entity('ChatwootAccountUserMembership', [
            'id' => 'ai', 'aiPrompt' => '<p>Projection</p>', 'aiPromptEditorState' => $this->state([$record, $context]),
        ]);
        [$em, $select] = $this->queryFixture('ChatwootAccountUserMembership', [$membership]);
        $acl = $this->createMock(Acl::class);
        foreach (['checkScope', 'checkField', 'checkEntityRead'] as $method) $acl->method($method)->willReturn(true);
        $references = $this->createMock(EditorReferences::class);
        $references->method('resolve')->willReturn([[...$record, 'available' => true, 'label' => 'Current label']]);
        $references->expects($this->once())->method('context')->with($context, [])->willReturn(null);
        $result = (new PromptPreparation($em, $acl, $select, $references))->prepare('ai', []);
        $this->assertStringContainsString('Current label [Account:acme]', $result);
        $this->assertStringContainsString('[Unresolved context: Primary contact]', $result);
        $this->assertStringNotContainsString('Stale label', $result);
    }

    public function testAccessibleFileAndPageDocumentsShareTheSupportedRecordReferenceContract(): void
    {
        [$em, $select] = $this->queryFixture('Document', [
            $this->entity('Document', ['id' => 'file', 'name' => 'Evidence file', 'contentType' => 'File']),
            $this->entity('Document', ['id' => 'page', 'name' => 'Overview page', 'contentType' => 'Page']),
        ]);
        $acl = $this->createMock(Acl::class);
        foreach (['checkScope', 'checkField', 'checkEntityRead'] as $method) $acl->method($method)->willReturn(true);
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturn(true);
        $metadata->method('getAll')->willReturn((object) [
            'scopes' => (object) ['Document' => (object) ['entity' => true, 'object' => true, 'tab' => true]],
            'entityDefs' => (object) ['Document' => (object) ['fields' => (object) ['name' => (object) []]]],
        ]);
        $results = (new EditorReferences($em, $select, $acl, $metadata))->resolve(array_map(
            fn ($id) => ['kind' => 'record', 'entityType' => 'Document', 'recordId' => $id], ['file', 'page']));
        $this->assertTrue($results[0]['available']);
        $this->assertTrue($results[1]['available']);
    }
}
