{{#if showConditionsAll}}
    <div>
        <div class="all-conditions"></div>
    </div>
{{/if}}

{{#if showConditionsAny}}
    <div
        {{#if marginForConditionsAny}}style="margin-top: var(--20px)"{{/if}}
    >
        <div class="any-conditions"></div>
    </div>
{{/if}}

{{#if showFormula}}
    <div
        {{#if marginForFormula}}style="margin-top: var(--20px)"{{/if}}
    >
        <label class="control-label"
        >{{translate 'Formula' scope='Workflow'}}
            <small class="text-muted"> · {{translate 'formulaInfo' category='texts' scope='Workflow'}}</small></label>
        <div
            class="formula-conditions clearfix"
            {{#if readOnly}}style="margin-left: 10px;"{{/if}}
        ></div>
    </div>
{{/if}}

{{#if showNoData}}
    <div class="list-container margin-top">
        <div class="no-data">
            {{translate 'No Data'}}
        </div>
    </div>
{{/if}}

<!--suppress CssUnusedSymbol -->
<style>
    .all-conditions,
    .any-conditions {
        > div {
            .cell {
                > label {
                    color: var(--gray-soft);
                }

                &,
                > .clearfix {
                    > a[data-action="removeCondition"] {
                        visibility: hidden;
                    }
                }

                &:hover {
                    &,
                    > .clearfix {
                        > a[data-action="removeCondition"] {
                            visibility: visible;
                        }
                    }
                }

                padding: var(--2px) var(--10px) var(--10px) var(--10px);
                border: var(--1px) solid var(--default-border-color);
                border-radius: var(--border-radius-small);

                a[data-action="removeCondition"] {
                    position: relative;
                }

                &:last-child {
                    margin-bottom: var(--4px);
                }

                &[data-role="field-cell"] {
                    max-width: calc(var(--340px) + var(--10px));
                }

                &[data-role="group-cell"] {
                    > .condition {
                        margin-top: var(--6px);
                    }

                    &:has(> [data-action="removeCondition"]) {
                        > .condition {
                            margin-top: var(--20px);
                        }
                    }
                }

                .condition {
                    .row + .row {
                        > div:not(:empty) {
                            margin-top: var(--8px);
                        }
                    }

                    padding-top: var(--2px);
                }

                padding-top: var(--4px);
            }

            [data-role="operator"] {
                font-size: 0.85em;
                margin: var(--5px) var(--4px) var(--4px);
                user-select: none;

                &:last-child {
                    display: none;
                }
            }
        }

        .items {
            + .btn-group {
                margin-top: var(--2px);
            }
        }

        > .no-data {
            margin-right: var(--10px);
        }

        > .items {
            > .cell[data-role="group-cell"] {
                max-width: calc(var(--340px) + var(--10px) + var(--22px));

                &:has(.cell[data-role="group-cell"]) {
                    max-width: calc(var(--340px) + var(--10px) + var(--22px) + var(--22px));
                }
            }
        }
    }
</style>
