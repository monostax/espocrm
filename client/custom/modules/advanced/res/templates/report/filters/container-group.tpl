<a
    role="button"
    tabindex="0"
    class="pull-right"
    data-action="removeGroup"
    style="position: relative;"
><span class="fas fa-times"></span></a>

<div>
    <span
    >{{#if showGroupTypeLabel}}<span>{{translate type category='filtersGroupTypes' scope='Report'}}</span> {{/if}}(</span>
</div>
<!--suppress CssOverwrittenProperties -->
<div
    class="node"
    style="
        {{#unless noOffset}}
            left: var(--20px); width: calc(100% - var(--20px)); position: relative;
        {{else}}
            margin-left: var(--18px); width: calc(100% - var(--18px));
        {{/unless}}
    "
>{{{node}}}</div>
<div>)</div>
