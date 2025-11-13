{{#each days}}
    <div>
        <label style="cursor: pointer;">
            <input
                type="checkbox"
                data-name="{{../name}}"
                value="{{@index}}"
                {{#ifPropEquals ../selectedWeekdays @index true}} checked {{/ifPropEquals}}
                class="main-element form-checkbox"
            > {{.}}
        </label>
    </div>
{{/each}}
