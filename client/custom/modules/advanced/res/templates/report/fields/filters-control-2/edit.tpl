<div class="node-row">
    <div class="node">{{{node}}}</div>
</div>
<!--suppress CssUnusedSymbol -->
<style>
    .node-row {
        width: 64%;

        .item-list {
            > div {
                &:not(.node-operator) {
                    padding: var(--10px) var(--10px);
                    border: var(--1px) solid var(--default-border-color);
                    border-radius: var(--border-radius-small);
                    margin: var(--10px) 0;
                }

                label.control-label {
                    color: var(--gray-soft);
                    margin-bottom: var(--3px);
                }

                a.remove-filter,
                a[data-action="removeGroup"] {
                    position: relative;
                    top: var(--minus-4px);
                }

                &.node-operator {
                    > div {
                        margin-bottom: var(--2px);
                    }
                }

                &:has(> .filter) {
                    max-width: var(--340px);
                }

                &:has(> .clearfix) {
                    max-width: var(--400px);
                }

                &,
                > .clearfix {
                    > a[data-action="removeGroup"] {
                        visibility: hidden;
                    }
                }

                &:hover {
                    &,
                    > .clearfix {
                        > a[data-action="removeGroup"] {
                            visibility: visible;
                        }
                    }
                }
            }
        }
    }

    @media screen and (max-width: 640px) {
        .node-row {
            width: 100%;
        }
    }
</style>
