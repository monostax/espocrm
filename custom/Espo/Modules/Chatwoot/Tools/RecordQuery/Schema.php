<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\RecordQuery;

use Espo\Core\Acl;
use Espo\Core\Acl\Exceptions\NotAvailable;
use Espo\Core\Utils\Acl\UserAclManagerProvider;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureRecordKnowledge\Tools\Scopes;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use RuntimeException;

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
    /** `origin` of a link inside a list merged with relation records. */
    public const ORIGINS = ['manual', 'assistant', 'enrichment'];
    /** enrichmentResults on Contact: the projection entity and its fields (enum options, or null for a flag). */
    public const RESULTS = 'ContactEnrichmentResult';
    public const RESULT_FIELDS = ['type' => ['email', 'phone'], 'valid' => null, 'added' => null];

    private Acl $acl;

    /** Every gate is the given user's, whoever the container's user is (jobs run as system on someone's behalf). */
    public function __construct(
        private Metadata $metadata,
        User $user,
        UserAclManagerProvider $acls,
        private EntityManager $em,
        private Language $language,
    ) {
        try {
            $this->acl = $acls->get($user)->createUserAcl($user);
        } catch (NotAvailable $e) {
            throw new RuntimeException($e->getMessage());
        }
    }

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
        // A role that hides the relationship's field hides the relationship from queries too.
        if (!$this->acl->checkField($entityType, $name, Acl\Table::ACTION_READ)) return null;
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

    /** The list name a related type gets on any record: Contact -> contacts, Opportunity -> opportunities. */
    public static function plural(string $type): string
    {
        $name = lcfirst($type);
        if (preg_match('/[^aeiou]y$/D', $name)) return substr($name, 0, -1) . 'ies';
        if (preg_match('/(s|x|z|ch|sh)$/D', $name)) return $name . 'es';
        return $name . 's';
    }

    /**
     * What a list name on $entityType reads from:
     *  native   a relationship the entity defines
     *  merged   a relationship to many records of a related type, plus confirmed relation records with them
     *  relation confirmed relation records only (a related type without such a relationship, or `related.<Type>`)
     *  results  enrichmentResults on Contact
     * fields are the link details a row of the list has.
     *
     * @return ?array{kind: string, entity: string, link: ?string, tenant: bool, fields: list<string>, many: bool}
     */
    public function source(string $entityType, string $name): ?array
    {
        if ($name === 'enrichmentResults') {
            return $entityType === 'Contact' && $this->resultsReadable()
                ? ['kind' => 'results', 'entity' => self::RESULTS, 'link' => null, 'tenant' => true, 'fields' => [], 'many' => true]
                : null;
        }
        if (str_starts_with($name, 'related.')) {
            $type = substr($name, 8);
            return in_array($type, $this->relatedTypes($entityType), true)
                ? ['kind' => 'relation', 'entity' => $type, 'link' => null, 'tenant' => $this->tenantOwned($type), 'fields' => ['origin'], 'many' => true]
                : null;
        }
        $related = null;
        foreach ($this->relatedTypes($entityType) as $type) {
            if (self::plural($type) === $name) $related = $type;
        }
        $link = $this->link($entityType, $name);
        if ($link) {
            $many = in_array($link['type'], ['hasMany', 'hasChildren'], true);
            $merged = $many && $related === $link['entity'];
            return ['kind' => $merged ? 'merged' : 'native', 'entity' => $link['entity'], 'link' => $name,
                'tenant' => $link['tenant'], 'fields' => $merged ? ['origin'] : [], 'many' => $many];
        }
        // A relationship the user may not follow never falls back to relation records of the same name.
        if ($this->metadata->get(['entityDefs', $entityType, 'links', $name]) !== null || $related === null) return null;
        return ['kind' => 'relation', 'entity' => $related, 'link' => null, 'tenant' => $this->tenantOwned($related), 'fields' => ['origin'], 'many' => true];
    }

    /**
     * Enrichment results carry whether a found email or phone was verified and added to the Contact, never the value.
     * Readable by whoever may use enrichment and see verification results, the enrichment status and the channels those
     * flags describe.
     */
    private function resultsReadable(): bool
    {
        if (!$this->em->getDefs()->hasEntity(self::RESULTS)) return false;
        foreach (['emailEnrichmentResult', 'phoneEnrichmentResult'] as $field) {
            if ($this->kind('Contact', $field) === null) return false;
        }
        // Same gate as the Contact's verification status and history (FeatureCredits Verification\Access::contact).
        if ($this->acl->getPermissionLevel('useEnrichmentCredits') !== 'yes' || !$this->acl->checkScope('Contact', Acl\Table::ACTION_READ)) return false;
        foreach (['emailAddress', 'phoneNumber', 'firstName', 'lastName', 'channelIdentitiesData'] as $field) {
            if (!$this->acl->checkField('Contact', $field, Acl\Table::ACTION_READ)) return false;
        }
        return true;
    }

    /** @var array<string, bool> */
    private array $evidenceBacked = [];

    /**
     * Whether any confirmed relation between these two types rests on a document. Until one does, a query needs no
     * document access check at all (and requires evidence-free relations, so one created meanwhile is left out).
     */
    public function evidenceBacked(string $one, string $other): bool
    {
        $key = $one < $other ? "$one|$other" : "$other|$one";
        if (!isset($this->evidenceBacked[$key])) {
            $q = $this->em->getPDO()->prepare("SELECT 1 FROM record_relation WHERE source_document_id IS NOT NULL AND status = 'confirmed'
                AND deleted = FALSE AND ((subject_type = ? AND object_type = ?) OR (subject_type = ? AND object_type = ?)) LIMIT 1");
            $q->execute([$one, $other, $other, $one]);
            $this->evidenceBacked[$key] = (bool) $q->fetchColumn();
        }
        return $this->evidenceBacked[$key];
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
        $names = array_keys($this->metadata->get(['entityDefs', $entityType, 'links']) ?? []);
        foreach ($this->relatedTypes($entityType) as $type) $names[] = self::plural($type);
        if ($entityType === 'Contact') $names[] = 'enrichmentResults';
        foreach (array_unique($names) as $name) {
            $source = $this->source($entityType, $name);
            if (!$source) continue;
            $label = match ($source['kind']) {
                'native', 'merged' => $this->language->translate($name, 'links', $entityType),
                'relation' => $this->language->translate($source['entity'], 'scopeNamesPlural'),
                default => $this->language->translate('enrichmentResults', 'recordQuery', 'Global'),
            };
            $link = ['name' => $name, 'entity' => $source['entity'], 'many' => $source['many'], 'label' => $label];
            if ($source['fields']) $link['linkFields'] = [[
                'name' => 'origin', 'kind' => 'enum', 'label' => $this->language->translate('origin', 'recordQuery', 'Global'),
                'options' => array_map(fn (string $value) => [
                    'value' => $value, 'label' => $this->language->translate($value, 'recordQueryOrigins', 'Global'),
                ], self::ORIGINS),
            ]];
            // The rows are not a record type of their own: their fields come with the list.
            if ($source['kind'] === 'results') $link['schema'] = ['entityType' => self::RESULTS, 'links' => [], 'related' => [],
                'fields' => array_map(fn (string $field, ?array $options) => [
                    'name' => $field, 'kind' => $options ? 'enum' : 'bool',
                    'label' => $this->language->translate($field, 'recordQueryResultFields', 'Global'),
                    'options' => array_map(fn (string $value) => [
                        'value' => $value, 'label' => $this->language->translate($value, 'recordQueryResultTypes', 'Global'),
                    ], $options ?? []),
                ], array_keys(self::RESULT_FIELDS), self::RESULT_FIELDS),
            ];
            $links[] = $link;
        }
        $related = array_map(
            fn (string $type) => ['name' => $type, 'label' => $this->language->translate($type, 'scopeNamesPlural')],
            $this->relatedTypes($entityType),
        );
        return (object) ['entityType' => $entityType, 'fields' => $fields, 'links' => $links, 'related' => $related];
    }
}
