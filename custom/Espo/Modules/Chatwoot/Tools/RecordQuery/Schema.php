<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\RecordQuery;

use Espo\Core\Acl;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureRecordKnowledge\Tools\Scopes;
use Espo\ORM\EntityManager;

/** What a record query may name on an entity: the fields and relationships this user can read. */
class Schema
{
    private const KINDS = [
        'varchar' => 'text', 'text' => 'text', 'wysiwyg' => 'text', 'url' => 'text', 'number' => 'text',
        'email' => 'text', 'phone' => 'text', 'personName' => 'text',
        'enum' => 'enum', 'multiEnum' => 'array', 'array' => 'array', 'checklist' => 'array', 'bool' => 'bool',
        'int' => 'number', 'float' => 'number', 'currency' => 'number', 'currencyConverted' => 'number',
        'date' => 'date', 'datetime' => 'datetime', 'datetimeOptional' => 'datetime',
    ];
    /** Not stored as a column, but the ORM knows how to filter them. */
    private const VIRTUAL = ['email', 'phone', 'personName', 'currencyConverted'];

    public function __construct(
        private Metadata $metadata,
        private Acl $acl,
        private EntityManager $em,
        private Language $language,
    ) {}

    /** @return ?string text | enum | array | bool | number | date | datetime */
    public function kind(string $entityType, string $name): ?string
    {
        if ($name === 'id') return 'text';
        $def = $this->metadata->get(['entityDefs', $entityType, 'fields', $name]);
        if (!is_array($def) || !empty($def['disabled']) || !empty($def['utility'])) return null;
        $type = $def['type'] ?? '';
        $kind = self::KINDS[$type] ?? null;
        if (!$kind || !$this->em->getDefs()->getEntity($entityType)->hasAttribute($name)) return null;
        if (in_array($name, $this->acl->getScopeForbiddenFieldList($entityType), true)) return null;
        if (!empty($def['notStorable']) && !in_array($type, self::VIRTUAL, true)) {
            $custom = array_keys($this->metadata->get(['selectDefs', $entityType, 'whereItemConverterClassNameMap']) ?? []);
            if (!array_filter($custom, fn (string $key) => str_starts_with($key, $name . '_'))) return null;
        }
        return $kind;
    }

    public function tenantOwned(string $entityType): bool
    {
        return $this->em->getDefs()->getEntity($entityType)->hasAttribute('tenantId');
    }

    /** @return ?array{entity: string, type: string, tenant: bool} */
    public function link(string $entityType, string $name): ?array
    {
        $def = $this->metadata->get(['entityDefs', $entityType, 'links', $name]);
        $foreign = $def['entity'] ?? null;
        $type = $def['type'] ?? '';
        if (!$foreign || !empty($def['disabled']) || !in_array($type, ['belongsTo', 'hasMany', 'hasOne', 'hasChildren'], true)) return null;
        // Tenant isolation: relationships are followed only from tenant-owned records (never onward from a user or a
        // team), and the Compiler pins a tenant-owned related record to the tenant of the record it hangs from.
        $entity = $this->em->getDefs()->getEntity($entityType);
        if (!$entity->hasRelation($name) || !$this->tenantOwned($entityType)) return null;
        // Related records are always read through the foreign entity's own access control.
        if (!$this->metadata->get(['scopes', $foreign, 'acl']) || !$this->acl->checkScope($foreign, Acl\Table::ACTION_READ)) return null;
        if (in_array($name, $this->acl->getScopeForbiddenLinkList($entityType), true)) return null;
        return ['entity' => $foreign, 'type' => $type, 'tenant' => $this->tenantOwned($foreign)];
    }

    /**
     * Entity types reachable as `related.<Type>`: through a confirmed relation record, in either direction.
     * Same gates as the relationships panel: a supported, readable type whose name the user may see.
     *
     * @return list<string>
     */
    public function relatedTypes(string $entityType): array
    {
        if (!$this->metadata->get(['scopes', 'RecordRelation', 'entity'])) return [];
        $types = Scopes::discover($this->metadata->getAll());
        if (!in_array($entityType, $types, true) || !$this->tenantOwned($entityType)) return [];
        return array_values(array_filter($types, fn (string $type) => $this->metadata->get(['scopes', $type, 'acl']) &&
            $this->acl->checkScope($type, Acl\Table::ACTION_READ) && $this->acl->checkField($type, 'name')));
    }

    /** Relation evidence lives in documents; without document access only evidence-free relations count. */
    public function documentsReadable(): bool
    {
        return $this->acl->checkScope('Document', Acl\Table::ACTION_READ) && $this->acl->checkField('Document', 'body');
    }

    /** For the editor's autocomplete; labels come in the user's language. */
    public function describe(string $entityType): object
    {
        $fields = [];
        foreach (array_keys($this->metadata->get(['entityDefs', $entityType, 'fields']) ?? []) as $name) {
            $kind = $this->kind($entityType, $name);
            if (!$kind) continue;
            $options = [];
            foreach ($this->metadata->get(['entityDefs', $entityType, 'fields', $name, 'options']) ?? [] as $value) {
                if (!is_string($value) || $value === '') continue;
                $options[] = ['value' => $value, 'label' => (string) $this->language->translateOption($value, $name, $entityType)];
            }
            $fields[] = ['name' => $name, 'kind' => $kind, 'label' => $this->language->translate($name, 'fields', $entityType), 'options' => $options];
        }
        $links = [];
        foreach (array_keys($this->metadata->get(['entityDefs', $entityType, 'links']) ?? []) as $name) {
            $link = $this->link($entityType, $name);
            if (!$link) continue;
            $links[] = [
                'name' => $name, 'entity' => $link['entity'], 'many' => in_array($link['type'], ['hasMany', 'hasChildren'], true),
                'label' => $this->language->translate($name, 'links', $entityType),
            ];
        }
        $related = array_map(
            fn (string $type) => ['name' => $type, 'label' => $this->language->translate($type, 'scopeNamesPlural')],
            $this->relatedTypes($entityType),
        );
        return (object) ['entityType' => $entityType, 'fields' => $fields, 'links' => $links, 'related' => $related];
    }
}
