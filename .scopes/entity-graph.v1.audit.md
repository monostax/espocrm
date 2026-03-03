# Audit Report: Polymorphic Graph Entity Specification (v1)

**Audited File:** `/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/entity-graph.v1.md`  
**Audit Date:** 2026-03-03  
**Auditor:** Review Agent

---

## Audit Summary

**Risk Level:** HIGH  
**Files Reviewed:** 15+ reference files examined  
**Findings:** Critical: 3 | Warnings: 6 | Suggestions: 4

The scope document demonstrates a solid understanding of EspoCRM patterns and references valid existing implementations (Funnel, OpportunityStage). However, **critical gaps exist** around: (1) missing selectDefs for ACL filtering, (2) incomplete orphan cleanup strategy (bidirectional edges not addressed), and (3) missing entity class definitions. The architecture is sound but implementation readiness is blocked by unspecified dependencies.

---

## Readiness Assessment

**Verdict:** NEEDS REVISION

The scope must address the following design-level issues before implementation:

1. **Add missing selectDefs for GraphType** - Required for team-based ACL filtering to work
2. **Clarify bidirectional edge handling** - Orphan cleanup and validation logic must account for bidirectional GraphType settings
3. **Add Entity class definitions** - Typed entities improve maintainability and are consistent with existing Funnel pattern
4. **Verify Case entity scope** - Ensure Case bottom panels are being added to the correct scope location

---

## Critical Findings (MUST address before implementation)

### 1. Missing selectDefs for GraphType ACL Filtering
- **Location:** File manifest (not listed)
- **Evidence:** Funnel uses `selectDefs/Funnel.json` with `accessControlFilterClassNameMap` pointing to `OnlyTeam` filter class. GraphType scope lists team-based ACL but no selectDefs are specified in the manifest.
- **Assumption:** Team-based ACL works without selectDefs configuration.
- **Risk:** ACL filtering will fail silently; users will see all GraphTypes regardless of team membership.
- **Remedy:** Add to manifest:
  - `custom/Espo/Modules/Global/Resources/metadata/selectDefs/GraphType.json`
  - `custom/Espo/Modules/Global/Classes/Select/GraphType/AccessControlFilters/OnlyTeam.php`
  - Reference: `/custom/Espo/Modules/Global/Resources/metadata/selectDefs/Funnel.json`

### 2. Incomplete Orphan Cleanup Strategy
- **Location:** Files 3-7 (Orphan Cleanup Hooks)
- **Evidence:** Scope defines 5 separate `afterRemove` hooks (Contact, Account, Lead, Opportunity, Case) that query for Graph records where the entity appears as `parent` OR `related`.
- **Assumption:** Each entity type only needs to clean up its own side of relationships.
- **Risk:** For **bidirectional** GraphTypes (where `bidirectional=true`), deleting entity A that is `related` to entity B should also delete the mirror edge where B is `parent` and A is `related`. The current cleanup only handles direct references, potentially leaving orphaned mirror edges.
- **Remedy:** The cleanup hooks must check if the deleted entity appears on either side, and if the GraphType is bidirectional, ensure complete cleanup. Alternatively, implement a single centralized cleanup service that handles all edge cases.

### 3. Missing Entity Class Definitions
- **Location:** File manifest (not listed)
- **Evidence:** Funnel pattern includes `Entities/Funnel.php` with typed getters/setters. OpportunityStage follows same pattern. Scope lists 31 files but no entity classes.
- **Assumption:** EspoCRM will auto-generate entity classes at runtime.
- **Risk:** Without typed entity classes, IDE autocomplete fails, type safety is reduced, and the codebase becomes harder to maintain. Also breaks consistency with existing Global module entities.
- **Remedy:** Add to manifest:
  - `custom/Espo/Modules/Global/Entities/Graph.php`
  - `custom/Espo/Modules/Global/Entities/GraphType.php`
  - Reference: `/custom/Espo/Modules/Global/Entities/Funnel.php`

---

## Warnings (SHOULD address)

### 1. Graph ACL May Need Custom Ownership Checker
- **Location:** File 12 (Graph ACL Definition)
- **Evidence:** Graph uses team inheritance from GraphType (Decision #3). Funnel pattern uses both AccessChecker AND OwnershipChecker (`aclDefs/Funnel.json` has both class names).
- **Concern:** Graph entities may not properly report ownership for "onlyMy" filtering without an OwnershipChecker. The scope lists empty ACL definition for Graph.
- **Suggestion:** Evaluate if Graph needs `ownershipCheckerClassNameMap` for proper "onlyMy" filter behavior, or document why it's not needed.

### 2. Missing MultiEnum Options Source
- **Location:** File 9 (GraphType Entity Definition)
- **Evidence:** `allowedFromTypes` and `allowedToTypes` are defined as `multiEnum` type. In EspoCRM, multiEnum fields need `options` array or `optionsPath` to define available values.
- **Concern:** The scope doesn't specify how the entity type options (Contact, Account, Lead, Opportunity, Case) will be populated in the MultiEnum dropdown.
- **Suggestion:** Add `options` array to the field definition or define a custom options provider that returns available entity types.

### 3. layouts.json Modification Strategy Undefined
- **Location:** File 32 (Global App Layouts)
- **Evidence:** Current `layouts.json` only has entries for Settings, Preferences, Account, Contact, Opportunity. No "Data" section or standalone entity pattern exists for adding GraphType.
- **Concern:** The scope says "Add GraphType to appropriate menu section (likely under 'Data' or as standalone entity)" but doesn't specify the exact JSON structure needed.
- **Suggestion:** Define exact layout entry structure, including whether GraphType needs its own top-level menu item or should be nested under existing sections.

### 4. adminPanel.json Modification Undefined
- **Location:** File 33 (Admin Panel Configuration)
- **Evidence:** Current `adminPanel.json` only has a "system" section with Activities Layout entry. No "Data" or "Entities" section exists.
- **Concern:** Adding GraphType to admin panel requires knowing the correct panel structure. EspoCRM admin panels are organized by category.
- **Suggestion:** Specify exact adminPanel.json structure and whether GraphType should be admin-managed or user-managed (since it has team ACL).

### 5. Lead/Opportunity/Case Bottom Panels Path Uncertainty
- **Location:** Files 25-27 (New Files)
- **Evidence:** Contact and Account have existing `bottomPanelsDetail.json` files in `custom/Espo/Modules/Global/Resources/layouts/`. Lead, Opportunity, and Case do NOT have custom layout files yet.
- **Concern:** The scope marks these as "NEW FILE" but doesn't verify if Lead/Opportunity/Case layouts should be in Global module or their native modules (Crm).
- **Suggestion:** Verify the correct module scope for Lead/Opportunity/Case layouts. If they belong to the Crm module, paths should be adjusted.

### 6. CascadeDelete Hook May Conflict with Orphan Cleanup
- **Location:** `custom/Espo/Modules/Global/Hooks/Common/CascadeDelete.php`
- **Evidence:** Global module has a generic CascadeDelete hook that reads `cascadeDelete` configuration from entity metadata and runs at order 5.
- **Concern:** The scope's individual orphan cleanup hooks don't specify their execution order. If they run before CascadeDelete, they might miss relationships that CascadeDelete would otherwise handle.
- **Suggestion:** Explicitly set `$order` on orphan cleanup hooks to run after CascadeDelete (e.g., order 10) or document why the order doesn't matter.

---

## Suggestions (CONSIDER addressing)

### 1. Consider Unified Cleanup Hook
- **Context:** 5 separate cleanup hook files with nearly identical logic
- **Observation:** Each hook queries Graph records where entity appears on parent or related side.
- **Enhancement:** Implement a single `Common/GraphCleanup` hook that handles all entity types by checking the entity type dynamically. Reduces code duplication and maintenance burden.

### 2. Consider Graph Weight Validation
- **Context:** Graph entity has `weight` field defined as float with min/max
- **Observation:** Scope doesn't specify the actual min/max values or validation logic.
- **Enhancement:** Define specific weight range (e.g., 0.0 to 1.0, or 0 to 100) and add validation in the beforeSave hook.

### 3. Consider Adding Graph Direction Constraints
- **Context:** Graph has `direction` enum with "unidirectional" and "bidirectional"
- **Observation:** The relationship between this field and GraphType's `bidirectional` boolean isn't clarified.
- **Enhancement:** Document whether Graph direction can override GraphType setting, or if GraphType.bidirectional determines the default/allowed values for Graph.direction.

### 4. Consider Index Strategy Review
- **Context:** Graph entity will have indexes on parentType, parentId, relatedType, relatedId, graphTypeId
- **Observation:** With polymorphic queries, composite indexes may be more effective than individual indexes.
- **Enhancement:** Consider composite indexes like `(parentType, parentId)` and `(relatedType, relatedId)` for better query performance when fetching all Graphs for a specific entity.

---

## Validated Items

The following aspects of the plan are well-supported:

- **✅ Hook pattern validation** - `Hooks/Funnel/EnsureSingleDefault.php` confirms use of `BeforeSave` interface, dependency injection, and proper hook order pattern.
- **✅ ACL AccessChecker pattern** - `Classes/Acl/Funnel/AccessChecker.php` confirms exact pattern for team-based access with `AccessEntityCREDSChecker` interface and `DefaultAccessCheckerDependency` trait.
- **✅ EntityDefs structure** - `entityDefs/Funnel.json` and `entityDefs/Note.json` confirm `linkParent` field type and `belongsToParent` link pattern.
- **✅ Scope definition pattern** - `scopes/Funnel.json` confirms `type: "Base"`, `acl: true`, `object: true` pattern.
- **✅ i18n structure** - `i18n/en_US/Funnel.json` and `i18n/pt_BR/Funnel.json` confirm field/label/link/tooltips structure.
- **✅ ClientDefs pattern** - `clientDefs/Funnel.json` confirms iconClass, boolFilterList, filterList, relationshipPanels structure.
- **✅ bottomPanelsDetail pattern** - Contact and Account layouts confirm JSON structure for relationship panels.
- **✅ Team ACL inheritance decision** - Funnel pattern uses `team` (single link) while GraphType plan uses `teams` (linkMultiple) - this is a conscious design choice that should be documented.

---

## Recommended Next Steps

1. **Add selectDefs and AccessControlFilters** for GraphType to enable proper team-based filtering
2. **Define entity class files** Graph.php and GraphType.php for type safety
3. **Clarify bidirectional edge handling** in orphan cleanup strategy
4. **Verify Lead/Opportunity/Case layout paths** - determine if they belong in Global or Crm module
5. **Define exact layouts.json and adminPanel.json modifications** with specific JSON structures
6. **Specify MultiEnum options source** for allowedFromTypes/allowedToTypes fields
7. **Document Graph vs GraphType team field difference** (Funnel uses `team`, GraphType uses `teams`)

---

## Evidence References

| File Examined | Purpose | Location |
|---------------|---------|----------|
| `Funnel.json` (entityDefs) | Entity pattern with team ACL | `/custom/Espo/Modules/Global/Resources/metadata/entityDefs/Funnel.json` |
| `Funnel.json` (scopes) | Scope pattern | `/custom/Espo/Modules/Global/Resources/metadata/scopes/Funnel.json` |
| `Funnel.json` (aclDefs) | ACL checker registration | `/custom/Espo/Modules/Global/Resources/metadata/aclDefs/Funnel.json` |
| `Funnel.json` (selectDefs) | Select filter registration | `/custom/Espo/Modules/Global/Resources/metadata/selectDefs/Funnel.json` |
| `Funnel.json` (clientDefs) | UI configuration | `/custom/Espo/Modules/Global/Resources/metadata/clientDefs/Funnel.json` |
| `AccessChecker.php` | ACL pattern | `/custom/Espo/Modules/Global/Classes/Acl/Funnel/AccessChecker.php` |
| `EnsureSingleDefault.php` | Hook pattern | `/custom/Espo/Modules/Global/Hooks/Funnel/EnsureSingleDefault.php` |
| `ValidateStageFunnel.php` | Validation hook pattern | `/custom/Espo/Modules/Global/Hooks/Opportunity/ValidateStageFunnel.php` |
| `CascadeDelete.php` | Generic cleanup hook | `/custom/Espo/Modules/Global/Hooks/Common/CascadeDelete.php` |
| `Note.json` | linkParent pattern | `/application/Espo/Resources/metadata/entityDefs/Note.json` |
| `bottomPanelsDetail.json` | Layout pattern | `/custom/Espo/Modules/Global/Resources/layouts/Contact/bottomPanelsDetail.json` |
| `Funnel.json` (i18n) | Translation pattern | `/custom/Espo/Modules/Global/Resources/i18n/en_US/Funnel.json` |
| `Funnel.php` | Entity class pattern | `/custom/Espo/Modules/Global/Entities/Funnel.php` |
| `OpportunityStage.json` | Related entity pattern | `/custom/Espo/Modules/Global/Resources/metadata/entityDefs/OpportunityStage.json` |
| `layouts.json` | App layout config | `/custom/Espo/Modules/Global/Resources/metadata/app/layouts.json` |
| `adminPanel.json` | Admin panel config | `/custom/Espo/Modules/Global/Resources/metadata/app/adminPanel.json` |

---

*End of Audit Report*
