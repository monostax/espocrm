<div class="channel-identities-container">
{{#each itemList}}
    <div class="input-group channel-identity-edit-block" data-index="{{index}}">
        <span class="input-group-item">
            <select
                data-property-type="channelType"
                class="form-control radius-left"
            >
                {{#each typeOptionDataList}}
                <option value="{{value}}"{{#if selected}} selected{{/if}}>{{label}}</option>
                {{/each}}
            </select>
        </span>
        <span class="input-group-item input-group-item-middle">
            <input
                type="text"
                class="form-control channel-identity-source-id no-margin-shifting"
                value="{{sourceId}}"
                autocomplete="espo-{{../name}}"
                maxlength="255"
            >
        </span>
        <span class="input-group-btn">
            <button
                class="btn btn-default btn-icon{{#if isPrimary}} active{{/if}}"
                type="button"
                data-action="switchPrimary"
                data-toggle="tooltip"
                data-placement="top"
                title="{{translate 'isPrimary' category='fields' scope='ContactChannelIdentity'}}"
            >
                <span class="fas fa-star fa-sm{{#unless isPrimary}} text-muted{{/unless}}"></span>
            </button>
            <button
                class="btn btn-link btn-icon radius-right"
                type="button"
                tabindex="-1"
                data-action="removeChannelIdentity"
                data-toggle="tooltip"
                data-placement="top"
                title="{{translate 'Remove'}}"
            >
                <span class="fas fa-times"></span>
            </button>
        </span>
    </div>
{{/each}}
</div>

<button
    class="btn btn-default btn-icon"
    type="button"
    data-action="addChannelIdentity"
><span class="fas fa-plus"></span></button>
