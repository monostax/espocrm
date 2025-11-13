<div class="grid-auto-fill-xs filters-row"></div>

<!--suppress CssUnusedSymbol -->
<style>
    .filters-row {
        grid-column-gap: var(--10px);

        > .column {
            > div {
                padding: var(--8px);
                border: var(--1px) solid var(--default-border-color);
                border-radius: var(--border-radius-small);

                .column-label {
                    min-height: calc(var(--40px) + var(--4px));
                    user-select: none;
                }

                .column-field {
                    margin-bottom: var(--2px);

                    .control-label {
                        margin-bottom: var(--1px);
                    }
                }
            }

            margin-bottom: var(--10px);
        }
    }
</style>
