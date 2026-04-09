<div class="chatwoot-conversation-bridge">
    {{#ifEqual bridgeState 'loading'}}
    <div class="bridge-loading" style="display: flex; align-items: center; justify-content: center; height: 200px;">
        <span class="fas fa-spinner fa-spin" style="font-size: 24px; color: #999;"></span>
    </div>
    {{/ifEqual}}

    {{#ifEqual bridgeState 'not-found'}}
    <div class="bridge-not-found" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 200px; color: #999;">
        <span class="fas fa-search" style="font-size: 48px; margin-bottom: 16px;"></span>
        <p style="font-size: 14px;">{{errorMessage}}</p>
    </div>
    {{/ifEqual}}

    {{#ifEqual bridgeState 'error'}}
    <div class="bridge-error" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 200px; color: #d9534f;">
        <span class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 16px;"></span>
        <p style="font-size: 14px;">{{errorMessage}}</p>
    </div>
    {{/ifEqual}}

    <div class="bridge-detail-container"></div>
</div>
