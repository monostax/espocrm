<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Classes\Select;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\Applier\AdditionalApplier;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\ORM\Query\SelectBuilder;
use Espo\Modules\FeatureRecordKnowledge\Tools\Scopes;

/** Applied to all Document selects, including lists, fulltext search and exports. */
class DocumentAccess implements AdditionalApplier
{
    private static bool $ordinaryDocumentParent = false;
    public function __construct(private SelectBuilderFactory $select, private Acl $acl, private Scopes $scopes) {}

    public function apply(SelectBuilder $queryBuilder, SearchParams $searchParams): void
    {
        if (self::$ordinaryDocumentParent) {
            $queryBuilder->where(['knowledgeRecordType' => null]);
            return;
        }
        $or = [['knowledgeRecordType' => null]];
        foreach ($this->scopes->all() as $type) {
            if (!$this->acl->checkScope($type, 'read') || !$this->acl->checkField($type, 'name')) continue;
            try {
                self::$ordinaryDocumentParent = $type === 'Document';
                $parent = $this->select->create()->from($type)->withStrictAccessControl()->buildQueryBuilder()->select(['id'])->build();
                $or[] = ['knowledgeRecordType' => $type, 'knowledgeRecordId=s' => $parent];
            } catch (Forbidden) {
                continue;
            } finally {
                self::$ordinaryDocumentParent = false;
            }
        }
        $queryBuilder->where(['OR' => $or]);
    }
}
