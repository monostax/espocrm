{{#if url}}
<a href="{{url}}" title="{{nameValue}}" class="label label-md label-state label-{{styleValue}}{{#if linkClass}} {{linkClass}}{{/if}}" data-id="{{idValue}}">{{nameValue}}</a>
{{else}}
<span class="label label-md label-state label-{{styleValue}}">{{nameValue}}</span>
{{/if}}
