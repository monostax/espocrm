<div class="field name-with-drawer-field">
    <div style="display: inline-flex; align-items: center; gap: 8px;">
        {{#if channelIconUrl}}
        <a href="javascript:" class="action" data-action="openDrawer" title="{{translate 'Open Chat' scope='ChatwootConversation'}}" style="display: inline-flex; align-items: center;">
            <img src="{{channelIconUrl}}" class="svg-icon-field-img" alt="{{channelType}}" width="16" height="16">
        </a>
        {{else}}
        <a href="javascript:" class="action" data-action="openDrawer" title="{{translate 'Open Chat' scope='ChatwootConversation'}}" style="color: #555; font-size: 16px; text-decoration: none;">
            <i class="fas fa-comments"></i>
        </a>
        {{/if}}
        <span>{{value}}</span>
    </div>
</div>
