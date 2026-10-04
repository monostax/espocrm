<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureKnowledgeBaseEditor;

use Espo\Core\Acl;
use Espo\Core\ORM\Type\FieldType;
use Espo\Core\Select\SelectBuilder as RecordSelectBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Text\ConfigProvider;
use Espo\Core\Select\Text\DefaultFilter;
use Espo\Core\Select\Text\Filter\Data;
use Espo\Core\Select\Text\MetadataProvider;
use Espo\Modules\FeatureKnowledgeBaseEditor\Classes\Select\CandidateTextFilter;
use Espo\ORM\Entity;
use Espo\ORM\Query\SelectBuilder;
use PHPUnit\Framework\TestCase;

class CandidateTextFilterTest extends TestCase
{
    private function metadata(): MetadataProvider
    {
        $metadata = $this->createMock(MetadataProvider::class);
        $metadata->method('getAttributeType')->willReturn(Entity::VARCHAR);
        $metadata->method('getRelationEntityType')->willReturn('Contact');
        $metadata->method('getSimpleBelongsToKey')->willReturn('contactId');
        return $metadata;
    }

    private function defaultFilter(MetadataProvider $metadata): DefaultFilter
    {
        return new DefaultFilter('Account', $metadata, $this->createMock(ConfigProvider::class));
    }

    public function testCandidateIdsCannotReplaceOuterTenantAndRecordRestrictions(): void
    {
        $metadata = $this->metadata();
        $acl = $this->createMock(Acl::class);
        $acl->method('checkField')->willReturn(true);
        $select = $this->createMock(SelectBuilderFactory::class);
        $select->expects($this->never())->method('create');
        $filter = new CandidateTextFilter('Account', $this->defaultFilter($metadata), $metadata, $acl, $select);
        $builder = SelectBuilder::create()->from('Account')->where(['tenantId' => 'own-tenant', 'assignedUserId' => 'own-user']);
        $filter->apply($builder, Data::create('Antoine', ['name', 'description']));
        $where = $builder->build()->getWhere()->getRaw();
        $this->assertSame('own-tenant', $where['tenantId']);
        $this->assertSame('own-user', $where['assignedUserId']);
        $this->assertArrayHasKey('id=s', $where);
    }

    public function testDeniedFieldsCannotBeUsedAsASearchSideChannel(): void
    {
        $metadata = $this->metadata();
        $acl = $this->createMock(Acl::class);
        $acl->method('checkField')->willReturn(false);
        $filter = new CandidateTextFilter('Account', $this->defaultFilter($metadata), $metadata, $acl,
            $this->createMock(SelectBuilderFactory::class));
        $builder = SelectBuilder::create()->from('Account');
        $filter->apply($builder, Data::create('private-value', ['name', 'description']));
        $this->assertSame(['id' => null], $builder->build()->getWhere()->getRaw());
    }

    public function testDeniedRelatedScopeCannotInfluenceMatches(): void
    {
        $metadata = $this->metadata();
        $acl = $this->createMock(Acl::class);
        $acl->method('checkField')->willReturn(true);
        $acl->method('checkScope')->with('Contact', 'read')->willReturn(false);
        $select = $this->createMock(SelectBuilderFactory::class);
        $select->expects($this->never())->method('create');
        $filter = new CandidateTextFilter('Account', $this->defaultFilter($metadata), $metadata, $acl, $select);
        $builder = SelectBuilder::create()->from('Account');
        $filter->apply($builder, Data::create('Antoine', ['contact.name']));
        $this->assertSame(['id' => null], $builder->build()->getWhere()->getRaw());
    }

    public function testRelatedDisplaySearchRetainsRelatedTenantRestriction(): void
    {
        $metadata = $this->metadata();
        $acl = $this->createMock(Acl::class);
        $acl->method('checkField')->willReturn(true);
        $acl->method('checkScope')->willReturn(true);
        $recordBuilder = $this->createMock(RecordSelectBuilder::class);
        $recordBuilder->expects($this->once())->method('from')->with('Contact')->willReturnSelf();
        $recordBuilder->expects($this->once())->method('withStrictAccessControl')->willReturnSelf();
        $recordBuilder->method('buildQueryBuilder')->willReturn(
            SelectBuilder::create()->from('Contact')->where(['tenantId' => 'own-tenant']));
        $select = $this->createMock(SelectBuilderFactory::class);
        $select->method('create')->willReturn($recordBuilder);
        $filter = new CandidateTextFilter('Account', $this->defaultFilter($metadata), $metadata, $acl, $select);
        $builder = SelectBuilder::create()->from('Account')->where(['tenantId' => 'own-tenant']);
        $filter->apply($builder, Data::create('Antoine', ['contact.name']));
        $ids = $builder->build()->getWhere()->getRaw()['id=s'];
        $branch = $ids->getFromQuery()->getRaw()['queries'][0];
        $relatedIds = $branch->getWhere()->getRaw()['contactId=s'];
        $this->assertSame('own-tenant', $relatedIds->getFromQuery()->getWhere()->getRaw()['tenantId']);
        $this->assertSame('Antoine%', $relatedIds->getWhere()->getRaw()['relatedSearch.value*']);
    }

    public function testPersonNamePrefixUsesPartsButWildcardKeepsFullNameSemantics(): void
    {
        $metadata = $this->metadata();
        $metadata->method('getFieldType')->willReturn(FieldType::PERSON_NAME);
        $metadata->method('getPersonNameAttributes')->willReturn(['firstName', 'lastName']);
        $filter = $this->defaultFilter($metadata);
        $builder = SelectBuilder::create()->from('Account');
        $filter->apply($builder, Data::create('Antoine', ['name']));
        $this->assertSame(['OR' => [['firstName*' => 'Antoine%'], ['lastName*' => 'Antoine%']]],
            $builder->build()->getWhere()->getRaw());
        $builder = SelectBuilder::create()->from('Account');
        $filter->apply($builder, Data::create('Antoine Smith', ['name']));
        $this->assertSame(['OR' => ['name*' => 'Antoine Smith%']], $builder->build()->getWhere()->getRaw());
    }

    public function testRelatedPersonNameGuardIncludesWhitespaceAndScopesEveryBranch(): void
    {
        $metadata = $this->metadata();
        $metadata->method('getFieldType')->willReturnCallback(fn ($type, $field) =>
            $type === 'Contact' && $field === 'name' ? FieldType::PERSON_NAME : null);
        $metadata->method('getPersonNameAttributes')->willReturn(['firstName', 'lastName']);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('checkField')->willReturn(true);
        $recordBuilder = $this->createMock(RecordSelectBuilder::class);
        $recordBuilder->method('from')->willReturnSelf();
        $recordBuilder->expects($this->once())->method('withStrictAccessControl')->willReturnSelf();
        $recordBuilder->method('buildQueryBuilder')->willReturn(
            SelectBuilder::create()->from('Contact')->where(['tenantId' => 'own-tenant']));
        $select = $this->createMock(SelectBuilderFactory::class);
        $select->method('create')->willReturn($recordBuilder);
        $filter = new CandidateTextFilter('Account', $this->defaultFilter($metadata), $metadata, $acl, $select);
        $builder = SelectBuilder::create()->from('Account');
        $filter->apply($builder, Data::create('Antoine', ['contact.name']));
        $ids = $builder->build()->getWhere()->getRaw()['id=s'];
        $candidate = $ids->getFromQuery()->getRaw()['queries'][0];
        $relatedIds = $candidate->getWhere()->getRaw()['contactId=s'];
        $branches = $relatedIds->getFromQuery()->getRaw()['queries'];
        $this->assertCount(4, $branches);
        foreach ($branches as $i => $branch) {
            $where = $branch->getWhere()->getRaw();
            $this->assertSame('own-tenant', $where['tenantId']);
            $part = $i < 2 ? 'firstName' : 'lastName';
            $this->assertSame($i % 2 === 0 ? 'Antoine%' : ' %', $where[$part . '*']);
        }
        $this->assertSame('Antoine%', $relatedIds->getWhere()->getRaw()['relatedSearch.value*']);
    }
}
