# Scope: Polymorphic Graph Entity System (v2)

**Status:** READY FOR IMPLEMENTATION  
**Scope Version:** 2.0  
**Based on Audit:** entity-graph.v1.audit.md  
**Date:** 2026-03-03  
**Module:** Global

---

## Overview

Implement a polymorphic entity relationship system allowing flexible graph connections between any CRM entities (Contact, Account, Lead, Opportunity, Case). The system consists of two main entities:

1. **GraphType** - Defines relationship types (e.g., "Refers", "Partner", "Influencer") with team-based ACL
2. **Graph** - Individual relationship instances connecting two entities with a specific GraphType

---

## Decisions

| # | Decision | Alternatives Considered | Rationale |
|---|----------|------------------------|-----------|
| 1 | Use `linkParent` field type for polymorphic relationships | Custom field implementation | Matches EspoCRM `Note` entity pattern; native support for parentType/parentId |
| 2 | GraphType uses `teams` (linkMultiple) vs Funnel's `team` (single) | Single team like Funnel | GraphTypes may need to be shared across multiple teams; documented difference from Funnel pattern |
| 3 | Unified orphan cleanup hook | 5 separate entity-specific hooks | Reduces code duplication; single hook handles all entity types via dynamic type checking |
| 4 | Graph inherits team ACL from GraphType | Independent ACL on Graph | Simpler permissions; Graph visibility controlled by GraphType team membership |
| 5 | Composite indexes on (parentType, parentId) and (relatedType, relatedId) | Individual column indexes | Better query performance for polymorphic lookups |
| 6 | No custom OwnershipChecker for Graph | Add OwnershipChecker like Funnel | Graph uses team inheritance from GraphType; "onlyMy" filtering handled via AccessChecker |
| 7 | MultiEnum options for allowedFromTypes/allowedToTypes | Custom options provider | Simpler implementation; define static options array in entityDefs |
| 8 | Layouts for Lead/Opportunity/Case in Global module | Place in Crm module | Consistent with Contact/Account pattern; Global module already overrides CRM layouts |
| 9 | Hook execution order 10 for orphan cleanup | Order 5 (before CascadeDelete) | Ensures CascadeDelete (order 5) runs first; prevents conflicts |
| 10 | Weight range 0.0 to 1.0 | 0 to 100 integer | Float allows finer granularity; matches typical graph weight conventions |

---

## Entity Definitions

### GraphType Entity

**Purpose:** Define relationship types with constraints and team-based access control.

**Key Fields:**
- `name` (varchar, required) - Display name (e.g., "Refers", "Partner")
- `description` (text) - Optional description
- `teams` (linkMultiple, required) - Teams that can use this GraphType
- `allowedFromTypes` (multiEnum) - Entity types that can be the "parent" in this relationship
- `allowedToTypes` (multiEnum) - Entity types that can be the "related" in this relationship
- `bidirectional` (bool, default: false) - If true, relationship is symmetric (A→B implies B→A)
- `isActive` (bool, default: true) - Soft delete flag
- `weightDefault` (float, 0.0-1.0) - Default weight for Graphs of this type
- `color` (varchar) - UI color for visual distinction

**Links:**
- `teams` - hasMany to Team
- `graphs` - hasMany to Graph (foreign: graphType)
- Standard createdBy/modifiedBy

**Indexes:**
- name (unique with deleted)
- isActive

---

### Graph Entity

**Purpose:** Individual relationship instance connecting two entities.

**Key Fields:**
- `graphType` (link, required) - Reference to GraphType
- `parent` (linkParent, required) - The "from" entity (polymorphic)
- `related` (linkParent, required) - The "to" entity (polymorphic)
- `weight` (float, 0.0-1.0) - Relationship strength
- `direction` (enum: "unidirectional", "bidirectional") - Override for symmetric relationships
- `description` (text) - Notes about the relationship
- `isActive` (bool, default: true)

**Links:**
- `graphType` - belongsTo to GraphType
- `parent` - belongsToParent (polymorphic)
- `related` - belongsToParent (polymorphic)
- Standard createdBy/modifiedBy

**Indexes:**
- Composite: (parentType, parentId)
- Composite: (relatedType, relatedId)
- graphTypeId
- isActive

---

## File Manifest

### Files to CREATE (ordered by complexity/risk, highest first)

#### 1. `custom/Espo/Modules/Global/Classes/Select/GraphType/AccessControlFilters/OnlyTeam.php`
**Purpose:** Team-based ACL filtering for GraphType queries.  
**Complexity:** HIGH - Critical for security; incorrect implementation exposes all GraphTypes.  
**Pattern Reference:** `custom/Espo/Modules/Global/Classes/Select/Funnel/AccessControlFilters/OnlyTeam.php`  
**Key Implementation:**
- Implements `Espo\Core\Select\AccessControl\Filter`
- Injects User via constructor
- Filters GraphType by teams relationship (linkMultiple vs Funnel's single team)
- Uses QueryBuilder to add WHERE condition checking EntityTeam junction table

```php
// Key difference from Funnel: teams is linkMultiple, not single link
// Need to check EntityTeam junction table or use teamsIds field
$queryBuilder->where([
    'id=s' => $this->entityManager->getQueryBuilder()
        ->select('entityId')
        ->from('EntityTeam')
        ->where([
            'entityType' => 'GraphType',
            'teamId' => $teamIdList,
        ])
        ->build()
]);
```

---

#### 2. `custom/Espo/Modules/Global/Hooks/Common/GraphCleanup.php`
**Purpose:** Unified hook to clean up Graph records when any entity is deleted.  
**Complexity:** HIGH - Must handle bidirectional edge cleanup correctly; orphaned edges break data integrity.  
**Pattern Reference:** `custom/Espo/Modules/Global/Hooks/Common/CascadeDelete.php` (for hook order and pattern)  
**Key Implementation:**
- Implements `BeforeRemove` interface (not AfterRemove - need entity data before deletion)
- Static $order = 10 (runs after CascadeDelete which is order 5)
- Queries Graph entities where deleted entity appears as parent OR related
- For bidirectional GraphTypes, deletes mirror edge if exists
- Must handle case where both ends are being deleted simultaneously (race condition)

```php
public function beforeRemove(Entity $entity, RemoveOptions $options): void
{
    $entityType = $entity->getEntityType();
    $entityId = $entity->getId();
    
    // Find all Graphs where this entity is parent or related
    $graphs = $this->entityManager->getRDBRepository('Graph')
        ->where([
            'OR' => [
                ['parentType' => $entityType, 'parentId' => $entityId],
                ['relatedType' => $entityType, 'relatedId' => $entityId],
            ]
        ])
        ->find();
    
    foreach ($graphs as $graph) {
        // Check if GraphType is bidirectional - handle mirror edge cleanup
        $this->cleanupGraph($graph, $entityType, $entityId);
    }
}
```

---

#### 3. `custom/Espo/Modules/Global/Classes/Acl/GraphType/AccessChecker.php`
**Purpose:** Custom ACL checks for GraphType entity.  
**Complexity:** MEDIUM - Must handle both team-based access and admin bypass.  
**Pattern Reference:** `custom/Espo/Modules/Global/Classes/Acl/Funnel/AccessChecker.php`  
**Key Differences from Funnel:**
- Funnel uses single `team` link; GraphType uses `teams` linkMultiple
- Need to check team membership via teamsIds or EntityTeam query
- Implements `AccessEntityCREDSChecker` interface

---

#### 4. `custom/Espo/Modules/Global/Classes/Acl/GraphType/OwnershipChecker.php`
**Purpose:** Ownership/team membership checker for "onlyMy" and "onlyTeam" filters.  
**Complexity:** MEDIUM  
**Pattern Reference:** `custom/Espo/Modules/Global/Classes/Acl/Funnel/OwnershipChecker.php`  
**Key Implementation:**
- `checkOwn()` returns false (no individual ownership concept)
- `checkTeam()` checks if user belongs to any of GraphType's teams
- Uses `teamsIds` field or queries EntityTeam junction

---

#### 5. `custom/Espo/Modules/Global/Hooks/Graph/ValidateEntityTypes.php`
**Purpose:** Validate that Graph's parent and related entities match GraphType's allowed types.  
**Complexity:** MEDIUM - Must query GraphType to get allowed types, then validate.  
**Pattern Reference:** `custom/Espo/Modules/Global/Hooks/Opportunity/ValidateStageFunnel.php`  
**Key Implementation:**
- BeforeSave hook, order 5
- Get GraphType entity from graphTypeId
- Check parentType against GraphType.allowedFromTypes
- Check relatedType against GraphType.allowedToTypes
- Throw BadRequest if validation fails
- Handle case where allowed types array is empty (allow all)

---

#### 6. `custom/Espo/Modules/Global/Hooks/GraphType/EnsureSingleDefaultWeight.php`
**Purpose:** If GraphType.weightDefault changes, optionally update existing Graphs (optional feature).  
**Complexity:** LOW-MEDIUM  
**Pattern Reference:** `custom/Espo/Modules/Global/Hooks/Funnel/EnsureSingleDefault.php`  
**Note:** May be deferred to v2.1; not critical for initial release.

---

#### 7. `custom/Espo/Modules/Global/Entities/Graph.php`
**Purpose:** Typed entity class with getters/setters for IDE support.  
**Complexity:** LOW  
**Pattern Reference:** `custom/Espo/Modules/Global/Entities/Funnel.php`, `OpportunityStage.php`  
**Key Methods:**
```php
public function getGraphTypeId(): ?string
public function getParentType(): ?string
public function getParentId(): ?string
public function getRelatedType(): ?string
public function getRelatedId(): ?string
public function getWeight(): float
public function setWeight(float $weight): self
// ... etc
```

---

#### 8. `custom/Espo/Modules/Global/Entities/GraphType.php`
**Purpose:** Typed entity class for GraphType.  
**Complexity:** LOW  
**Pattern Reference:** `custom/Espo/Modules/Global/Entities/Funnel.php`  
**Key Methods:**
```php
public function getName(): ?string
public function isBidirectional(): bool
public function getAllowedFromTypes(): array
public function getAllowedToTypes(): array
public function getTeamsIds(): array
// ... etc
```

---

#### 9. `custom/Espo/Modules/Global/Resources/metadata/selectDefs/GraphType.json`
**Purpose:** Register AccessControlFilters for team-based ACL.  
**Complexity:** LOW  
**Pattern Reference:** `custom/Espo/Modules/Global/Resources/metadata/selectDefs/Funnel.json`  
**Content:**
```json
{
    "accessControlFilterClassNameMap": {
        "onlyTeam": "Espo\\Modules\\Global\\Classes\\Select\\GraphType\\AccessControlFilters\\OnlyTeam"
    }
}
```

---

#### 10. `custom/Espo/Modules/Global/Resources/metadata/entityDefs/GraphType.json`
**Purpose:** Define GraphType entity schema.  
**Complexity:** MEDIUM  
**Pattern Reference:** `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Funnel.json`  
**Key Fields:**
- name (varchar, required)
- description (text)
- teams (linkMultiple, required)
- allowedFromTypes (multiEnum, options: ["Contact", "Account", "Lead", "Opportunity", "Case"])
- allowedToTypes (multiEnum, same options)
- bidirectional (bool, default: false)
- isActive (bool, default: true)
- weightDefault (float, min: 0, max: 1, default: 0.5)
- color (varchar, maxLength: 7) - for hex colors

---

#### 11. `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Graph.json`
**Purpose:** Define Graph entity schema with polymorphic relationships.  
**Complexity:** MEDIUM  
**Pattern Reference:** `application/Espo/Resources/metadata/entityDefs/Note.json` (for linkParent pattern)  
**Key Fields:**
- graphType (link, required)
- parent (linkParent, required) - polymorphic
- related (linkParent, required) - polymorphic
- weight (float, min: 0, max: 1, default: "weightDefault from GraphType")
- direction (enum: ["unidirectional", "bidirectional"], default: "unidirectional")
- description (text)
- isActive (bool, default: true)

**Key Links:**
```json
"parent": {
    "type": "belongsToParent",
    "entityList": ["Contact", "Account", "Lead", "Opportunity", "Case"]
},
"related": {
    "type": "belongsToParent",
    "entityList": ["Contact", "Account", "Lead", "Opportunity", "Case"]
}
```

---

#### 12. `custom/Espo/Modules/Global/Resources/metadata/scopes/GraphType.json`
**Purpose:** Define GraphType as an entity with ACL.  
**Complexity:** LOW  
**Pattern Reference:** `custom/Espo/Modules/Global/Resources/metadata/scopes/Funnel.json`

---

#### 13. `custom/Espo/Modules/Global/Resources/metadata/scopes/Graph.json`
**Purpose:** Define Graph entity scope.  
**Complexity:** LOW  
**Note:** ACL is true but uses simplified access (no object-level permissions, inherits from GraphType).

---

#### 14. `custom/Espo/Modules/Global/Resources/metadata/aclDefs/GraphType.json`
**Purpose:** Register custom ACL checkers for GraphType.  
**Complexity:** LOW  
**Pattern Reference:** `custom/Espo/Modules/Global/Resources/metadata/aclDefs/Funnel.json`  
**Content:**
```json
{
    "accessCheckerClassName": "Espo\\Modules\\Global\\Classes\\Acl\\GraphType\\AccessChecker",
    "ownershipCheckerClassName": "Espo\\Modules\\Global\\Classes\\Acl\\GraphType\\OwnershipChecker"
}
```

---

#### 15. `custom/Espo/Modules/Global/Resources/metadata/aclDefs/Graph.json`
**Purpose:** Register ACL for Graph entity.  
**Complexity:** LOW  
**Note:** May use empty configuration if using default ACL behavior with GraphType team inheritance.

---

#### 16. `custom/Espo/Modules/Global/Resources/metadata/clientDefs/GraphType.json`
**Purpose:** UI configuration for GraphType views.  
**Complexity:** LOW  
**Pattern Reference:** `custom/Espo/Modules/Global/Resources/metadata/clientDefs/Funnel.json`  
**Key Config:**
- iconClass: "fas fa-project-diagram" or similar
- boolFilterList: ["onlyMy"]
- filterList: ["all"]
- relationshipPanels: graphs (read-only inline edit disabled)

---

#### 17. `custom/Espo/Modules/Global/Resources/metadata/clientDefs/Graph.json`
**Purpose:** UI configuration for Graph views.  
**Complexity:** LOW  
**Key Config:**
- iconClass: "fas fa-link"
- boolFilterList: ["onlyMy"]
- relationshipPanels disabled (parent and related are polymorphic)

---

#### 18. `custom/Espo/Modules/Global/Resources/i18n/en_US/GraphType.json`
**Purpose:** English translations for GraphType.  
**Complexity:** LOW  
**Pattern Reference:** `custom/Espo/Modules/Global/Resources/i18n/en_US/Funnel.json`  
**Required Keys:**
- fields.* (all field names)
- links.* (all link names)
- labels.Create GraphType
- boolFilters.onlyMy
- presetFilters.all
- tooltips.bidirectional, weightDefault

---

#### 19. `custom/Espo/Modules/Global/Resources/i18n/en_US/Graph.json`
**Purpose:** English translations for Graph.  
**Complexity:** LOW  
**Required Keys:**
- fields.*
- links.*
- labels.Create Graph
- enums.direction.unidirectional, bidirectional

---

#### 20. `custom/Espo/Modules/Global/Resources/i18n/pt_BR/GraphType.json`
**Purpose:** Portuguese (Brazil) translations.  
**Complexity:** LOW

---

#### 21. `custom/Espo/Modules/Global/Resources/i18n/pt_BR/Graph.json`
**Purpose:** Portuguese (Brazil) translations.  
**Complexity:** LOW

---

#### 22. `custom/Espo/Modules/Global/Resources/layouts/GraphType/detail.json`
**Purpose:** Detail view layout for GraphType.  
**Complexity:** LOW  
**Pattern Reference:** `custom/Espo/Modules/Global/Resources/layouts/Funnel/detail.json`  
**Rows:**
- Row 1: name, color
- Row 2: teams
- Row 3: allowedFromTypes, allowedToTypes
- Row 4: bidirectional, weightDefault, isActive
- Row 5: description

---

#### 23. `custom/Espo/Modules/Global/Resources/layouts/GraphType/list.json`
**Purpose:** List view columns.  
**Complexity:** LOW

---

#### 24. `custom/Espo/Modules/Global/Resources/layouts/Graph/detail.json`
**Purpose:** Detail view layout for Graph.  
**Complexity:** LOW  
**Rows:**
- Row 1: graphType, weight
- Row 2: parent (linkParent field)
- Row 3: related (linkParent field)
- Row 4: direction, isActive
- Row 5: description

---

#### 25. `custom/Espo/Modules/Global/Resources/layouts/Graph/list.json`
**Purpose:** List view columns for Graph.  
**Complexity:** LOW

---

#### 26-30. Bottom Panel Layouts for Related Entities
**Purpose:** Add Graph relationship panels to Contact, Account, Lead, Opportunity, Case.  
**Complexity:** LOW  
**Pattern Reference:** `custom/Espo/Modules/Global/Resources/layouts/Contact/bottomPanelsDetail.json`

**Files:**
- 26: `custom/Espo/Modules/Global/Resources/layouts/Contact/bottomPanelsDetail.json` - **EDIT** (add graphs entry)
- 27: `custom/Espo/Modules/Global/Resources/layouts/Account/bottomPanelsDetail.json` - **EDIT** (add graphs entry)
- 28: `custom/Espo/Modules/Global/Resources/layouts/Lead/bottomPanelsDetail.json` - **NEW** (create file with graphs entry)
- 29: `custom/Espo/Modules/Global/Resources/layouts/Opportunity/bottomPanelsDetail.json` - **NEW** (create file with graphs entry)
- 30: `custom/Espo/Modules/Global/Resources/layouts/Case/bottomPanelsDetail.json` - **NEW** (create file with graphs entry)

**Content Pattern (for NEW files):**
```json
{
    "graphs": {
        "sticked": false,
        "index": 5
    }
}
```

**Edit Pattern (for EXISTING files):** Add "graphs" entry to existing JSON object.

---

### Files to EDIT

#### 31. `custom/Espo/Modules/Global/Resources/metadata/app/layouts.json`
**Change:** Add entries for GraphType and Graph to register layouts in Global module.  
**Pattern:** Follow existing entries for Funnel, OpportunityStage.

#### 32. `custom/Espo/Modules/Global/Resources/metadata/app/adminPanel.json`
**Change:** Add GraphType to admin panel for configuration management.  
**Note:** GraphType should be admin-configurable; Graph is data entry.

#### 33. `custom/Espo/Modules/Global/Resources/layouts/Contact/bottomPanelsDetail.json`
**Change:** Add `graphs` relationship panel entry.

#### 34. `custom/Espo/Modules/Global/Resources/layouts/Account/bottomPanelsDetail.json`
**Change:** Add `graphs` relationship panel entry.

---

### Files to CONSIDER

#### 35. `custom/Espo/Modules/Global/Controllers/GraphType.php`
**Consideration:** May need custom controller if default record controller insufficient.  
**Decision:** Defer - use default controller unless specific requirements emerge.

#### 36. `custom/Espo/Modules/Global/Controllers/Graph.php`
**Consideration:** May need custom controller for polymorphic handling.  
**Decision:** Defer - use default controller initially.

#### 37. `custom/Espo/Modules/Global/Resources/routes.json`
**Consideration:** Custom API routes for graph visualization endpoints.  
**Decision:** Defer to v2.1 - visualization is future enhancement.

#### 38. `custom/Espo/Modules/Global/Rebuild/SeedGraphTypes.php`
**Consideration:** Seed data for common relationship types ("Refers", "Partner", etc.).  
**Decision:** Include - helpful for initial setup.

---

## Implementation Sequence

### Phase 1: Core Schema (Required First)
1. Entity classes (Graph.php, GraphType.php)
2. Metadata files (entityDefs, scopes)
3. ACL infrastructure (aclDefs, AccessChecker, OwnershipChecker)
4. SelectDefs for team filtering

### Phase 2: Business Logic
5. Validation hooks (ValidateEntityTypes)
6. Cleanup hook (GraphCleanup)
7. EntityDefs finalization with indexes

### Phase 3: UI Layer
8. ClientDefs
9. Layout files
10. Bottom panels for related entities

### Phase 4: Localization & Polish
11. i18n files (en_US, pt_BR)
12. App metadata updates (layouts.json, adminPanel.json)
13. Optional: Seed data

---

## Critical Implementation Notes

### Team-Based ACL for linkMultiple (GraphType.teams)

Funnel uses a single `team` link (belongsTo). GraphType uses `teams` (linkMultiple). This requires different filtering logic:

**Funnel pattern (single team):**
```php
$queryBuilder->where(['teamId' => $teamIdList]);
```

**GraphType pattern (multiple teams):**
```php
$queryBuilder->where([
    'id=s' => $this->entityManager->getQueryBuilder()
        ->select('entityId')
        ->from('EntityTeam')
        ->where([
            'entityType' => 'GraphType',
            'teamId' => $teamIdList,
        ])
        ->build()
]);
```

### Bidirectional Edge Handling

When a Graph with bidirectional GraphType is created:
- System should create ONE record (not two)
- Direction field indicates if relationship is symmetric
- Queries must check both directions: `(parent=A AND related=B) OR (parent=B AND related=A)`

When an entity is deleted:
- Cleanup hook finds Graphs where entity is parent OR related
- For bidirectional GraphTypes, no special handling needed (single record represents both directions)

### Hook Execution Order

```
Order 5: CascadeDelete (generic cleanup)
Order 5: EnsureSingleDefault (Funnel pattern)
Order 10: ValidateEntityTypes (Graph validation)
Order 10: GraphCleanup (after CascadeDelete)
```

### Index Strategy

```json
"indexes": {
    "parentComposite": {
        "columns": ["parentType", "parentId"]
    },
    "relatedComposite": {
        "columns": ["relatedType", "relatedId"]
    }
}
```

Composite indexes are critical for polymorphic query performance.

---

## Validation Checklist

Before marking implementation complete:

- [ ] GraphType ACL: Users only see GraphTypes for their teams
- [ ] GraphType creation: Only admins or users with "create" permission
- [ ] Graph validation: parentType must be in GraphType.allowedFromTypes
- [ ] Graph validation: relatedType must be in GraphType.allowedToTypes
- [ ] Orphan cleanup: Deleting Contact removes all Graphs where it's parent or related
- [ ] Bidirectional handling: Creating A→B with bidirectional type allows finding via B→A query
- [ ] Bottom panels: Graphs panel appears on Contact, Account, Lead, Opportunity, Case detail views
- [ ] i18n: All UI strings translatable in en_US and pt_BR

---

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| ACL bypass exposing GraphTypes | Thorough testing of OnlyTeam filter with users in 0, 1, and multiple teams |
| Orphaned Graph records | Unit test GraphCleanup hook with each entity type; verify bidirectional cleanup |
| Performance on large graphs | Composite indexes; consider pagination in relationship panels |
| Circular relationship loops | UI-level prevention (don't allow entity to relate to itself) |
| MultiEnum options out of sync | Document that adding new entity types requires updating allowedFromTypes/allowedToTypes options |

---

## References

| Pattern | Location |
|---------|----------|
| Funnel entity (full pattern) | `custom/Espo/Modules/Global/Entities/Funnel.php` |
| Funnel entityDefs | `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Funnel.json` |
| Funnel ACL | `custom/Espo/Modules/Global/Classes/Acl/Funnel/` |
| Funnel selectDefs | `custom/Espo/Modules/Global/Resources/metadata/selectDefs/Funnel.json` |
| Note linkParent pattern | `application/Espo/Resources/metadata/entityDefs/Note.json` |
| CascadeDelete hook | `custom/Espo/Modules/Global/Hooks/Common/CascadeDelete.php` |
| Validation hook | `custom/Espo/Modules/Global/Hooks/Opportunity/ValidateStageFunnel.php` |
| Bottom panels | `custom/Espo/Modules/Global/Resources/layouts/Contact/bottomPanelsDetail.json` |

---

*End of Scope Document v2.0*
