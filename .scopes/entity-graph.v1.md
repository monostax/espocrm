# Scope Mapping: Polymorphic Graph Entity Specification

## Decisions

| # | Decision | Alternatives Considered | Rationale |
|---|----------|------------------------|-----------|
| 1 | Use `linkParent` field type for both `parent` and `related` | Separate link fields per entity type | `linkParent` provides true polymorphism without requiring schema changes when adding new entity types |
| 2 | Team ACL via `teams` field on GraphType | Custom ownership field | Native EspoCRM Team ACL provides built-in filtering and security |
| 3 | Team inheritance from GraphType to Graph | Explicit team selection on Graph | Auto-inheritance reduces user friction; Graph inherits vocabulary scope from its type |
| 4 | Single Graph entity vs. separate entities | Per-entity graph tables | Single table with polymorphic fields enables cross-entity queries and graph traversal |
| 5 | MultiEnum for allowedFromTypes/allowedToTypes | JSON array or separate join table | MultiEnum provides native UI, filtering, and validation in EspoCRM |
| 6 | `Global` module location | Dedicated `Graph` module | Follows convention of core team-scoped entities (Funnel, OpportunityStage) being in Global |
| 7 | Validate entity types in `beforeSave` hook | Database constraints | DB cannot validate against dynamic GraphType settings; hook provides flexible validation |
| 8 | Orphan cleanup via `afterRemove` hooks | Database cascade | `linkParent` has no FK constraints; hooks are the only cleanup mechanism |

---

## File Manifest

### Files to CREATE (ordered by complexity/risk, highest first)

#### 1. Graph Validation Hook (Highest Complexity)
**`custom/Espo/Modules/Global/Hooks/Graph/ValidateGraph.php`**
- **Purpose**: Central validation hook for Graph entity enforcing all business rules
- **Complexity**: Must validate: (a) GraphType exists and is accessible, (b) parent entity type is in GraphType.allowedFromTypes, (c) related entity type is in GraphType.allowedToTypes, (d) auto-inherit teams from GraphType
- **Key Patterns**: Follow `Espo\Modules\Global\Hooks\Funnel\EnsureSingleDefault` pattern for dependency injection and hook structure; use `BeforeSave` interface
- **Reference Files**: `/custom/Espo/Modules/Global/Hooks/Funnel/EnsureSingleDefault.php`

#### 2. ACL Access Checker for GraphType (High Complexity)
**`custom/Espo/Modules/Global/Classes/Acl/GraphType/AccessChecker.php`**
- **Purpose**: Enforces team-based access - users can only access GraphTypes belonging to their teams
- **Complexity**: Must implement `checkEntityRead`, `checkEntityEdit`, `checkEntityDelete`, `checkEntityStream` with team membership verification
- **Key Patterns**: Copy exact pattern from `Funnel\AccessChecker` - use `AccessEntityCREDSChecker` interface and `DefaultAccessCheckerDependency` trait
- **Reference Files**: `/custom/Espo/Modules/Global/Classes/Acl/Funnel/AccessChecker.php`

#### 3. Orphan Cleanup Hook for Contact (Medium Complexity)
**`custom/Espo/Modules/Global/Hooks/Contact/AfterRemoveGraphCleanup.php`**
- **Purpose**: Delete all Graph records where Contact is on parent or related side
- **Complexity**: Must query both `parentType = 'Contact' AND parentId = {id}` AND `relatedType = 'Contact' AND relatedId = {id}`
- **Key Patterns**: Implement `AfterRemove` interface; use EntityManager for deletion
- **Reference Files**: `/custom/Espo/Modules/Global/Hooks/Funnel/EnsureSingleDefault.php` (for hook structure)

#### 4. Orphan Cleanup Hook for Account
**`custom/Espo/Modules/Global/Hooks/Account/AfterRemoveGraphCleanup.php`**
- **Purpose**: Same pattern as Contact cleanup for Account entities
- **Pattern**: Identical logic, different entity type check

#### 5. Orphan Cleanup Hook for Lead
**`custom/Espo/Modules/Global/Hooks/Lead/AfterRemoveGraphCleanup.php`**
- **Purpose**: Same pattern for Lead entities

#### 6. Orphan Cleanup Hook for Opportunity
**`custom/Espo/Modules/Global/Hooks/Opportunity/AfterRemoveGraphCleanup.php`**
- **Purpose**: Same pattern for Opportunity entities

#### 7. Orphan Cleanup Hook for Case
**`custom/Espo/Modules/Global/Hooks/Case/AfterRemoveGraphCleanup.php`**
- **Purpose**: Same pattern for Case entities

#### 8. Graph Entity Definition (High Complexity - linkParent Configuration)
**`custom/Espo/Modules/Global/Resources/metadata/entityDefs/Graph.json`**
- **Purpose**: Defines Graph entity with polymorphic parent/related fields
- **Key Fields**:
  - `parent`: `linkParent` type with `entityList: ["Contact", "Account", "Lead", "Opportunity", "Case"]`
  - `related`: `linkParent` type with same entity list
  - `graphType`: `link` type, required
  - `direction`: `enum` with options `["unidirectional", "bidirectional"]`
  - `weight`: `float` type with min/max
  - `teams`: `linkMultiple` for ACL inheritance
- **Links Section**: Must define `belongsToParent` for parent and related fields; `belongsTo` for graphType; `hasMany` for teams
- **Indexes**: Add indexes on `parentType`, `parentId`, `relatedType`, `relatedId`, `graphTypeId` for query performance
- **Reference Files**: `/application/Espo/Resources/metadata/entityDefs/Note.json` (for linkParent pattern)

#### 9. GraphType Entity Definition
**`custom/Espo/Modules/Global/Resources/metadata/entityDefs/GraphType.json`**
- **Purpose**: Defines relationship vocabulary entity
- **Key Fields**:
  - `name`: required varchar
  - `description`: text
  - `allowedFromTypes`: `multiEnum` with entity options
  - `allowedToTypes`: `multiEnum` with entity options
  - `bidirectional`: bool, default false
  - `color`: varchar(7) for hex colors
  - `active`: bool, default true
  - `teams`: `linkMultiple`, required for ACL
- **Links Section**: `hasMany` for Graph records; `hasMany` for teams relation
- **Reference Files**: `/custom/Espo/Modules/Global/Resources/metadata/entityDefs/Funnel.json`

#### 10. Graph Scope Definition
**`custom/Espo/Modules/Global/Resources/metadata/scopes/Graph.json`**
- **Purpose**: Declares Graph as a Base entity with ACL, layouts, and tab visibility
- **Pattern**: Copy from Funnel scope, set `type: "Base"`, `acl: true`, `object: true`

#### 11. GraphType Scope Definition
**`custom/Espo/Modules/Global/Resources/metadata/scopes/GraphType.json`**
- **Purpose**: Declares GraphType as team-scoped entity
- **Pattern**: Copy from Funnel scope

#### 12. Graph ACL Definition
**`custom/Espo/Modules/Global/Resources/metadata/aclDefs/Graph.json`**
- **Purpose**: Standard ACL - no custom checker needed (uses team inheritance)
- **Content**: Empty object or standard scope-based ACL

#### 13. GraphType ACL Definition
**`custom/Espo/Modules/Global/Resources/metadata/aclDefs/GraphType.json`**
- **Purpose**: Points to custom AccessChecker for team-based access
- **Content**: `{"accessCheckerClassName": "Espo\\Modules\\Global\\Classes\\Acl\\GraphType\\AccessChecker"}`

#### 14. Graph ClientDefs
**`custom/Espo/Modules/Global/Resources/metadata/clientDefs/Graph.json`**
- **Purpose**: UI configuration for Graph entity
- **Key Configuration**: 
  - `iconClass`: Use graph-appropriate icon (e.g., `fas fa-project-diagram`)
  - `boolFilterList`: `["onlyMy"]` for team filtering
  - Dynamic handler for graphType field filtering by team

#### 15. GraphType ClientDefs
**`custom/Espo/Modules/Global/Resources/metadata/clientDefs/GraphType.json`**
- **Purpose**: UI configuration for GraphType entity
- **Key Configuration**: `iconClass`, relationship panels for Graph records

#### 16. Graph Layout: Detail
**`custom/Espo/Modules/Global/Resources/layouts/Graph/detail.json`**
- **Purpose**: Form layout for Graph record creation/editing
- **Structure**: Two-column layout with parent, related, graphType in first row; direction, weight in second row; note in full-width third row

#### 17. Graph Layout: Detail Small
**`custom/Espo/Modules/Global/Resources/layouts/Graph/detailSmall.json`**
- **Purpose**: Compact view for relationship panels
- **Structure**: Single column with essential fields

#### 18. Graph Layout: List
**`custom/Espo/Modules/Global/Resources/layouts/Graph/list.json`**
- **Purpose**: Column configuration for list view
- **Columns**: name (if added), parent, related, graphType, direction, weight

#### 19. Graph Layout: Filters
**`custom/Espo/Modules/Global/Resources/layouts/Graph/filters.json`**
- **Purpose**: Filter field configuration
- **Fields**: graphType, parent, related, direction, teams

#### 20. GraphType Layout: Detail
**`custom/Espo/Modules/Global/Resources/layouts/GraphType/detail.json`**
- **Purpose**: Form layout for GraphType
- **Structure**: name, teams in first row; color, active, bidirectional in second row; description full-width; allowedFromTypes, allowedToTypes in third row

#### 21. GraphType Layout: List
**`custom/Espo/Modules/Global/Resources/layouts/GraphType/list.json`**
- **Purpose**: Column configuration for GraphType list
- **Columns**: name, color (with color display), active, bidirectional, teams

#### 22. GraphType Layout: Filters
**`custom/Espo/Modules/Global/Resources/layouts/GraphType/filters.json`**
- **Purpose**: Filter configuration for GraphType
- **Fields**: active, bidirectional, teams

#### 23. Contact Bottom Panels (Add Graph Panel)
**`custom/Espo/Modules/Global/Resources/layouts/Contact/bottomPanelsDetail.json`**
- **Purpose**: Add Graph relationship panel to Contact detail view
- **Change**: Add `graphs` entry to existing JSON (merge with current content)
- **Current File**: Has activities, stream, etc. - add graphs panel

#### 24. Account Bottom Panels (Add Graph Panel)
**`custom/Espo/Modules/Global/Resources/layouts/Account/bottomPanelsDetail.json`**
- **Purpose**: Add Graph relationship panel to Account detail view
- **Change**: Add `graphs` entry with appropriate configuration

#### 25. Lead Bottom Panels (Add Graph Panel) - NEW FILE
**`custom/Espo/Modules/Global/Resources/layouts/Lead/bottomPanelsDetail.json`**
- **Purpose**: Add Graph relationship panel to Lead detail view
- **Content**: New file with graphs panel configuration

#### 26. Opportunity Bottom Panels (Add Graph Panel) - NEW FILE
**`custom/Espo/Modules/Global/Resources/layouts/Opportunity/bottomPanelsDetail.json`**
- **Purpose**: Add Graph relationship panel to Opportunity detail view

#### 27. Case Bottom Panels (Add Graph Panel) - NEW FILE
**`custom/Espo/Modules/Global/Resources/layouts/Case/bottomPanelsDetail.json`**
- **Purpose**: Add Graph relationship panel to Case detail view

#### 28. English i18n for Graph
**`custom/Espo/Modules/Global/Resources/i18n/en_US/Graph.json`**
- **Purpose**: Labels and translations for Graph entity
- **Content**: fields, links, labels, tooltips for all Graph fields

#### 29. English i18n for GraphType
**`custom/Espo/Modules/Global/Resources/i18n/en_US/GraphType.json`**
- **Purpose**: Labels and translations for GraphType entity
- **Content**: fields, links, labels, tooltips for all GraphType fields

#### 30. Portuguese i18n for Graph
**`custom/Espo/Modules/Global/Resources/i18n/pt_BR/Graph.json`**
- **Purpose**: Portuguese translations following project convention

#### 31. Portuguese i18n for GraphType
**`custom/Espo/Modules/Global/Resources/i18n/pt_BR/GraphType.json`**
- **Purpose**: Portuguese translations for GraphType

---

### Files to EDIT

#### 32. Global App Layouts (Add menu items)
**`custom/Espo/Modules/Global/Resources/metadata/app/layouts.json`**
- **Purpose**: Add GraphType to admin/user navigation
- **Change**: Add GraphType to appropriate menu section (likely under "Data" or as standalone entity)

#### 33. Admin Panel Configuration (if applicable)
**`custom/Espo/Modules/Global/Resources/metadata/app/adminPanel.json`**
- **Purpose**: Ensure GraphType appears in admin panel if it should be admin-managed
- **Change**: Add entry for GraphType management

---

### Files to DELETE
- None required

---

### Files to CONSIDER

#### 34. Graph RecordDefs (Optional)
**`custom/Espo/Modules/Global/Resources/metadata/recordDefs/Graph.json`**
- **Purpose**: Define record-level behaviors (read-only fields, etc.)
- **Decision**: Only needed if customizing record service behavior

#### 35. Custom Field View for GraphType Selection
**`client/custom/modules/global/src/views/graph/fields/graph-type.js`**
- **Purpose**: Client-side filtering of GraphType dropdown by user's teams
- **Decision**: Only needed if standard search filters are insufficient; can use `select` handler in clientDefs instead

#### 36. Custom Relationship Panel View
**`client/custom/modules/global/src/views/graph/panels/related-graphs.js`**
- **Purpose**: Custom panel showing Graphs where entity appears on either side
- **Decision**: Standard relationship panel may suffice; custom view needed for bidirectional display

---

### Related Files (for reference only, no changes needed)

| File Path | Purpose |
|-----------|---------|
| `/custom/Espo/Modules/Global/Resources/metadata/entityDefs/Funnel.json` | Entity definition pattern with team ACL |
| `/custom/Espo/Modules/Global/Classes/Acl/Funnel/AccessChecker.php` | Team-based ACL pattern |
| `/application/Espo/Resources/metadata/entityDefs/Note.json` | `linkParent` field configuration pattern |
| `/custom/Espo/Modules/Global/Hooks/Funnel/EnsureSingleDefault.php` | Hook structure and dependency injection |
| `/custom/Espo/Modules/Global/Resources/metadata/scopes/Funnel.json` | Scope definition pattern for custom entities |
| `/custom/Espo/Modules/Global/Resources/i18n/en_US/Funnel.json` | i18n structure pattern |

---

## Implementation Risk Summary

| Risk Area | Mitigation |
|-----------|------------|
| `linkParent` query performance | Indexes on `parentType`, `parentId`, `relatedType`, `relatedId` |
| Orphan Graph records | `afterRemove` hooks on all 5 entity types |
| Cross-team GraphType access | ACL AccessChecker + beforeSave validation |
| Invalid entity type relationships | MultiEnum constraint validation in beforeSave hook |
| Missing team inheritance | beforeSave hook auto-populates teams from GraphType |

## Testing Checklist (for implementation phase)

1. Create GraphType as Team A member - verify Team B cannot see it
2. Create Graph using GraphType - verify team auto-inherited
3. Try creating Graph with invalid entity type combination - verify rejected
4. Delete a Contact - verify all related Graph records cleaned up
5. Verify Graph panel appears on Contact, Account, Lead, Opportunity, Case detail views
6. Verify GraphType dropdown filters to user's teams in UI
