{{#if url}}
<a href="{{url}}" data-id="{{idValue}}" title="{{nameValue}}" class="label label-md label-state label-{{styleValue}}{{#if linkClass}} {{linkClass}}{{/if}}">{{nameValue}}</a>
{{else}}
<span class="label label-md label-state label-{{styleValue}}">{{nameValue}}</span>
{{/if}}
