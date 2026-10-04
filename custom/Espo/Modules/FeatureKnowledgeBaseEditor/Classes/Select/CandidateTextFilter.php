<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Classes\Select;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\Type\FieldType;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Text\DefaultFilter;
use Espo\Core\Select\Text\Filter;
use Espo\Core\Select\Text\Filter\Data;
use Espo\Core\Select\Text\MetadataProvider;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Part\Where\Comparison as Cmp;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\UnionBuilder;

/** Indexable search branches, intersected with the caller's existing ACL query. */
class CandidateTextFilter implements Filter
{
    public function __construct(
        private string $entityType,
        private DefaultFilter $defaultFilter,
        private MetadataProvider $metadata,
        private Acl $acl,
        private SelectBuilderFactory $select,
    ) {}

    public function apply(SelectBuilder $queryBuilder, Data $data): void
    {
        $attributes = array_values(array_filter($data->getAttributeList(), function ($attribute) {
            if (!str_contains($attribute, '.')) return $this->acl->checkField($this->entityType, $attribute);
            [$link, $field] = explode('.', $attribute, 2);
            $foreignType = $this->metadata->getRelationEntityType($this->entityType, $link);
            return $foreignType && $this->acl->checkField($this->entityType, $link) &&
                $this->acl->checkScope($foreignType, 'read') && $this->acl->checkField($foreignType, $field);
        }));
        $data = $data->withAttributeList($attributes);

        if ($data->getFullTextSearchWhereItem() || $data->getFilter() === '' ||
            (count($attributes) <= 1 && !array_filter($attributes, fn ($attribute) => str_contains($attribute, '.')))) {
            $this->defaultFilter->apply($queryBuilder, $data);
            return;
        }

        $union = UnionBuilder::create()->all();
        $count = 0;
        foreach ($attributes as $attribute) {
            $candidate = SelectBuilder::create()->from($this->entityType)->select('id');
            $this->defaultFilter->apply($candidate, $data->withAttributeList([$attribute]));

            if (str_contains($attribute, '.')) {
                [$link, $field] = explode('.', $attribute, 2);
                $foreignType = $this->metadata->getRelationEntityType($this->entityType, $link);
                try {
                    $relatedBuilder = $this->select->create()->from($foreignType)->withStrictAccessControl()
                        ->buildQueryBuilder()->select(['id', [$field, 'value']])->distinct()->order([]);
                } catch (Forbidden) {
                    continue;
                }
                // Shared modules can be loaded by a long-running worker still
                // using the previous core during a rolling upgrade. Retain the
                // original ACL-scoped join path until its helpers are available.
                $key = method_exists($this->metadata, 'getSimpleBelongsToKey') ?
                    $this->metadata->getSimpleBelongsToKey($this->entityType, $link) : null;
                $raw = $candidate->build()->getWhere()->getRaw();
                $value = $raw['OR'][$attribute . '*'] ?? null;
                $related = $relatedBuilder->build();
                $parts = method_exists($this->metadata, 'getPersonNameAttributes') &&
                    $this->metadata->getFieldType($foreignType, $field) === FieldType::PERSON_NAME ?
                    $this->metadata->getPersonNameAttributes($foreignType, $field) : [];
                if ($value !== null && $parts && !preg_match('/[\s%_*]/u', $data->getFilter()) &&
                    $value === addslashes($data->getFilter() . '%')) {
                    // A displayed person name can start with the prefix only
                    // when a component does, or a component has leading spaces
                    // removed by TRIM. Keep the exact display-name predicate
                    // below; this guard only narrows its indexed candidates.
                    $guard = UnionBuilder::create()->all();
                    foreach ($parts as $part) {
                        foreach ([$value, ' %'] as $prefix) {
                            $guard->query(SelectBuilder::create()->clone($related)
                                ->where(Cmp::like(Expr::column($part), $prefix))->build());
                        }
                    }
                    $related = $guard->build();
                }
                $relatedIds = SelectBuilder::create()->fromQuery($related, 'relatedSearch')->select('id');
                if ($key && $value !== null) {
                    // Evaluate the related display value once per related
                    // record, with that record's own ACL/tenant restrictions.
                    $candidate = SelectBuilder::create()->from($this->entityType)->select('id')
                        ->where(Cmp::in(Expr::column($key), $relatedIds
                            ->where(Cmp::like(Expr::column('relatedSearch.value'), $value))->build()));
                } else {
                    // Non-standard relations retain their original semantics,
                    // but may only match readable related records.
                    $candidate->where(Cmp::in(Expr::column($link . '.id'), $relatedIds->build()));
                }
            }

            $union->query($candidate->build());
            $count++;
        }

        if (!$count) {
            $queryBuilder->where(['id' => null]);
            return;
        }
        $ids = SelectBuilder::create()->fromQuery($union->build(), 'searchCandidates')->select('id')->build();
        // Append, never replace, the outer query's strict ACL/tenant clauses.
        $queryBuilder->where(Cmp::in(Expr::column('id'), $ids));
    }
}
