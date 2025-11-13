{{#each days}}
    <div>
        <label>
            <input
                type="checkbox" {{#ifPropEquals ../selectedWeekdays @index true}} checked {{/ifPropEquals}}
                class="main-element form-checkbox" disabled
            > {{.}} &nbsp;
        </label>
    </div>
{{/each}}
