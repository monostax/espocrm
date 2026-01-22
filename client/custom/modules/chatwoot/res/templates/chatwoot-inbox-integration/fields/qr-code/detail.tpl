{{#if isDraft}}
<div class="alert alert-info">
    <span class="fas fa-info-circle"></span>
    {{translate 'channelCreated' category='messages' scope='ChatwootInboxIntegration'}}
</div>
{{/if}}

{{#if isCreating}}
<div class="alert alert-warning">
    <span class="fas fa-spinner fa-spin"></span>
    {{translate 'Creating resources' category='labels' scope='ChatwootInboxIntegration'}}
</div>
{{/if}}

{{#if isPendingQr}}
<div class="qr-code-wrapper text-center">
    <div class="qr-code-container">
        <div class="loading">
            <span class="fas fa-spinner fa-spin"></span>
            {{translate 'Loading QR Code' category='labels' scope='ChatwootInboxIntegration'}}
        </div>
    </div>
</div>
{{/if}}

{{#if isConnecting}}
<div class="alert alert-info">
    <span class="fas fa-spinner fa-spin"></span>
    {{translate 'Connecting' category='labels' scope='ChatwootInboxIntegration'}}
</div>
{{/if}}

{{#if isActive}}
<div class="alert alert-success">
    <span class="fas fa-check-circle"></span>
    {{translate 'Channel is active' category='labels' scope='ChatwootInboxIntegration'}}
    {{#if whatsappName}}
    <br>
    <strong>{{whatsappName}}</strong>
    {{#if whatsappId}}<span class="text-muted"> ({{whatsappId}})</span>{{/if}}
    {{/if}}
</div>
{{/if}}

{{#if isDisconnected}}
<div class="alert alert-warning">
    <span class="fas fa-exclamation-triangle"></span>
    {{translate 'Channel is disconnected' category='labels' scope='ChatwootInboxIntegration'}}
</div>
{{/if}}

{{#if isFailed}}
<div class="alert alert-danger">
    <span class="fas fa-times-circle"></span>
    {{translate 'Channel failed' category='labels' scope='ChatwootInboxIntegration'}}
</div>
{{/if}}

<style>
.qr-code-wrapper {
    padding: 20px;
    background: #f5f5f5;
    border-radius: 8px;
    margin: 10px 0;
}

.qr-code-container {
    display: inline-block;
}

.qr-code-image {
    max-width: 280px;
    border: 4px solid #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-radius: 8px;
}

.qr-code-hint {
    margin-top: 15px;
    font-weight: 500;
    color: #333;
}
</style>
