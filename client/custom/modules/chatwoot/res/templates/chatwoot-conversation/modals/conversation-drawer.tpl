<style>
/* Drawer Modal Styles */
.modal.drawer-modal {
    display: flex !important;
    justify-content: flex-end;
    padding: 0 !important;
}

.modal.drawer-modal .modal-dialog {
    margin: 0;
    max-width: 500px;
    width: 100%;
    height: 100vh;
    transform: translateX(0);
    animation: slideInRight 0.3s ease-out;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
    }
    to {
        transform: translateX(0);
    }
}

.modal.drawer-modal .modal-content {
    height: 100%;
    border-radius: 0;
    border: none;
    display: flex;
    flex-direction: column;
}

.modal.drawer-modal .modal-header {
    display: none !important;
}

.modal.drawer-modal .modal-body {
    flex: 1;
    padding: 0;
    overflow: hidden;
}

.modal.drawer-modal .modal-footer {
    flex-shrink: 0;
    border-radius: 0;
}

.modal-backdrop.drawer-backdrop {
    background: rgba(0, 0, 0, 0.4);
}

.drawer-iframe-container {
    width: 100%;
    height: 100%;
    overflow: hidden;
}

.drawer-iframe-container iframe {
    width: 100%;
    height: 100%;
    border: none;
}

.drawer-error {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    padding: 40px;
    text-align: center;
    color: #6b7280;
}

.drawer-error i {
    font-size: 48px;
    margin-bottom: 16px;
    color: #d1d5db;
}

.drawer-error-text {
    font-size: 14px;
}
</style>

{{#if hasConversation}}
<div class="drawer-iframe-container">
    <iframe
        src="{{chatwootUrl}}"
        frameborder="0"
        allowfullscreen
        sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox"
    ></iframe>
</div>
{{else}}
<div class="drawer-error">
    <i class="fas fa-comment-slash"></i>
    <div class="drawer-error-text">
        {{#if errorMessage}}
        {{errorMessage}}
        {{else}}
        {{translate 'No conversation data available' scope='ChatwootConversation'}}
        {{/if}}
    </div>
</div>
{{/if}}

