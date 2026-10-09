<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureRecordKnowledge;

use Espo\Core\Acl;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilder as RecordSelectBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Modules\FeatureRecordKnowledge\Classes\Select\DocumentAccess;
use Espo\Modules\FeatureRecordKnowledge\Tools\Scopes;
use Espo\ORM\Query\SelectBuilder;
use PHPUnit\Framework\TestCase;

class DocumentAccessTest extends TestCase
{
    public function testCorrelatedLookupRetainsParentAclAndOmitsDeniedScopes(): void
    {
        $scopes = $this->createMock(Scopes::class);
        $scopes->method('all')->willReturn(['Contact', 'Credential']);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturnCallback(fn ($type) => $type === 'Contact');
        $acl->method('checkField')->willReturn(true);
        $builder = $this->createMock(RecordSelectBuilder::class);
        $builder->expects($this->once())->method('from')->with('Contact')->willReturnSelf();
        $builder->expects($this->once())->method('withStrictAccessControl')->willReturnSelf();
        $builder->method('buildQueryBuilder')->willReturn(SelectBuilder::create()->from('Contact')
            ->where(['assignedUserId' => 'permitted-user']));
        $factory = $this->createMock(SelectBuilderFactory::class);
        $factory->expects($this->once())->method('create')->willReturn($builder);
        $query = SelectBuilder::create()->from('Document')->where(['tenantId' => 'permitted-tenant']);
        (new DocumentAccess($factory, $acl, $scopes))->apply($query, SearchParams::create());
        $raw = $query->build()->getWhere()->getRaw();
        $this->assertSame('permitted-tenant', $raw['tenantId']);
        $this->assertCount(2, $raw['OR']);
        $this->assertSame(['knowledgeRecordType' => null], $raw['OR'][0]);
        $this->assertSame('Contact', $raw['OR'][1]['knowledgeRecordType']);
        $parent = $raw['OR'][1]['knowledgeRecordId=s'];
        $where = $parent->getWhere()->getRaw();
        $this->assertSame('permitted-user', $where['assignedUserId']);
        $this->assertStringContainsString('document.knowledgeRecordId', json_encode($where));
    }
}
