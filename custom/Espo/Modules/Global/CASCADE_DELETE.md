# Cascade Delete System

Generic metadata-driven cascade deletion for EspoCRM entities.

## Configuration

Add `cascadeDelete` to any entity's `entityDefs` metadata:

```json
{
    "cascadeDelete": {
        "links": ["conversations", "messages"],
        "junctionTables": [
            {"table": "EntityRelation", "column": "entityId"}
        ]
    }
}
```

| Property | Description |
|----------|-------------|
| `links` | Array of hasMany/hasOne link names to cascade delete |
| `junctionTables` | Array of junction tables to clean up (many-to-many relationships) |

## How It Works

1. Hook runs at **order 5** (before entity-specific hooks at order 10)
2. Reads `cascadeDelete` config from entity metadata
3. Deletes linked entities via `removeEntity()` with `cascadeParent: true`
4. Cleans up junction tables and EntityTeam records

## Options

| Option | Effect |
|--------|--------|
| `silent: true` | Skips cascade delete entirely |
| `cascadeParent: true` | Runs cascade delete but signals child hooks to skip remote API calls |

## Integration with Remote APIs

When deleting entities synced with remote APIs (Chatwoot, WAHA):

- **Local delete**: Parent's `DeleteFromChatwoot` hook calls remote API; children use `cascadeParent` to skip redundant API calls (remote handles its own cascade)
- **Sync job cleanup**: Uses `cascadeParent: true` to cascade locally without calling remote APIs (entity already deleted remotely)

## Example Chain

```
ChatwootAccount (delete)
├── CascadeDelete: deletes children with cascadeParent:true
│   ├── ChatwootAgent → DeleteFromChatwoot skipped (cascadeParent)
│   ├── ChatwootTeam → DeleteFromChatwoot skipped (cascadeParent)
│   └── ChatwootInbox → CascadeDelete runs again
│       └── ChatwootConversation → CascadeDelete runs again
│           └── ChatwootMessage
└── DeleteFromChatwoot: calls API to delete account (Chatwoot cascades remotely)
```

## Files

- `Hooks/Common/CascadeDelete.php` - Generic cascade delete hook
- Entity configs: `Resources/metadata/entityDefs/*.json`
