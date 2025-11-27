<div style="display: flex; align-items: center; gap: 8px;">{{#if iconClass}}<span class="{{iconClass}}" style="{{#if iconStyle}}{{iconStyle}}{{/if}}"></span>{{/if}}<a href="#{{scope}}/view/{{model.id}}" class="link" data-id="{{model.id}}" title="{{value}}">{{#if value}}{{value}}{{else}}{{translate 'None'}}{{/if}}</a></div>

