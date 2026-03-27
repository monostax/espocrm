# Grid Panel Layout

Display detail view panels side-by-side in a flexible grid layout via `gridRow` in `detail.json`.

## Quick Start

Add `"gridRow": N` to any panel in your entity's `detail.json`. Panels sharing the same `gridRow` number are placed side-by-side. The column count is determined automatically.

```json
[
    {
        "label": "Overview",
        "rows": [
            [{"name": "name"}, {"name": "status"}]
        ]
    },
    {
        "tabBreak": true,
        "tabLabel": "$MyTab",
        "name": "panelA",
        "label": "Panel A",
        "gridRow": 1,
        "rows": [[{"name": "fieldA"}]]
    },
    {
        "name": "panelB",
        "label": "Panel B",
        "gridRow": 1,
        "rows": [[{"name": "fieldB"}]]
    },
    {
        "name": "panelC",
        "label": "Panel C (Full Width)",
        "gridRow": 2,
        "rows": [[{"name": "fieldC"}]]
    },
    {
        "name": "panelD",
        "label": "Panel D",
        "gridRow": 3,
        "rows": [[{"name": "fieldD"}]]
    },
    {
        "name": "panelE",
        "label": "Panel E",
        "gridRow": 3,
        "rows": [[{"name": "fieldE"}]]
    },
    {
        "name": "panelF",
        "label": "Panel F",
        "gridRow": 3,
        "rows": [[{"name": "fieldF"}]]
    }
]
```

This produces:

| gridRow | Panels | Columns |
|---------|--------|---------|
| 1 | Panel A, Panel B | 2 |
| 2 | Panel C | 1 (full width) |
| 3 | Panel D, Panel E, Panel F | 3 |

## Rules

- **`gridRow` is optional** — panels without it render normally (full width, stacked).
- **Single panel in a gridRow** = full width with card styling.
- **Multiple panels in a gridRow** = side-by-side with equal column widths.
- **Column count** = number of panels in that gridRow (no explicit config needed).
- **Expand/collapse** works independently per panel. Expanded panels match the tallest in their row; collapsed panels shrink to header-only.
- **Tab compatibility** — grid layout works inside any tab. Panels without `gridRow` in the same tab are unaffected.
- **Backward compatible** — entities without any `gridRow` properties behave exactly like default EspoCRM.
- **Border radius** uses `var(--panel-border-radius)` for theme consistency.

## Implementation

The grid layout is built into `global:views/record/detail` which all entities inherit. No custom `recordViews.detail` registration is needed.

### How it works

1. After render, `_readGridRowFromLayout()` scans the layout for `gridRow` properties.
2. `applyGridLayout()` groups panels by `gridRow` and wraps them in flexbox row/column containers.
3. `selectTab()` and `adjustMiddlePanels()` use broader selectors to handle nested panels.
4. CSS rule ensures collapsed panels shrink: `.panels-grid-col > .panel.is-collapsed { height: auto !important; }`

### Key files

- **Source**: `client/custom/modules/global/src/views/record/detail.js`
- **Transpiled**: `client/custom/modules/global/lib/transpiled/src/views/record/detail.js`

## Future Possibilities

- **Relationship panels in grid**: Currently, relationship panels (sidePanels) are rendered in the `.side` container. Moving them into the middle grid layout would require extending the layout schema with a relationship-list field view reference.
