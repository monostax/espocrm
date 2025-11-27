<div style="display: flex; align-items: center; gap: 8px;">{{#if iconClass}}<span class="{{iconClass}}" style="{{#if iconStyle}}{{iconStyle}}{{/if}}"></span>{{/if}}{{#if isLink}}<a href="#{{scope}}/view/{{model.id}}" data-id="{{model.id}}" title="{{value}}">{{value}}</a>{{else}}{{value}}{{/if}}</div>

