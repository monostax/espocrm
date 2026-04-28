<span style="position: relative; display: inline-block; padding-left: 20px;">
    {{#if channelIconUrl}}
    <a href="javascript:" class="action" data-action="openDrawer" title="{{translate 'Open Chat' scope='ChatwootConversation'}}" style="position: absolute; top: 50%; left: 0; transform: translateY(-50%); line-height: 1;">
        <img src="{{channelIconUrl}}" alt="{{channelType}}" width="14" height="14">
    </a>
    {{else}}
    <a href="javascript:" class="action" data-action="openDrawer" title="{{translate 'Open Chat' scope='ChatwootConversation'}}" style="position: absolute; top: 50%; left: 0; transform: translateY(-50%); color: #555; font-size: 14px; text-decoration: none; line-height: 1;">
        <i class="fas fa-comments"></i>
    </a>
    {{/if}}
    <a href="#{{scope}}/view/{{model.id}}" class="link" data-id="{{model.id}}" title="{{value}}">{{#if value}}{{value}}{{else}}{{translate 'None'}}{{/if}}</a>
</span>
