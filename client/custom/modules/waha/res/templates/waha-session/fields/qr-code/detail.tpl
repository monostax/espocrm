{{#if showQrCode}}
    <div class="qr-code-container" style="text-align: center; padding: 20px;">
        {{#if isLoading}}
            <div class="loading-indicator">
                <span class="fas fa-spinner fa-spin fa-2x"></span>
                <p style="margin-top: 10px;">{{translate 'Loading QR Code' scope='WahaSession'}}...</p>
            </div>
        {{else if errorMessage}}
            <div class="alert alert-danger">
                <span class="fas fa-exclamation-triangle"></span>
                {{errorMessage}}
            </div>
        {{else if qrCodeDataUrl}}
            <div class="qr-code-wrapper">
                <p style="margin-bottom: 15px; font-weight: bold;">
                    <span class="fas fa-qrcode"></span>
                    {{translate 'Scan QR Code with WhatsApp' scope='WahaSession'}}
                </p>
                <img src="{{qrCodeDataUrl}}" 
                     alt="WhatsApp Lite (QR Code)" 
                     style="max-width: 300px; border: 1px solid #ddd; border-radius: 8px; padding: 10px; background: white;"
                />
                <p style="margin-top: 15px; color: #666; font-size: 0.9em;">
                    {{translate 'QR Code refreshes automatically' scope='WahaSession'}}
                </p>
            </div>
        {{/if}}
    </div>
{{else}}
    {{#ifEqual status 'WORKING'}}
        <div class="alert alert-success" style="margin: 0;">
            <span class="fas fa-check-circle"></span>
            {{translate 'Session is connected' scope='WahaSession'}}
        </div>
    {{else ifEqual status 'STOPPED'}}
        <div class="alert alert-secondary" style="margin: 0;">
            <span class="fas fa-stop-circle"></span>
            {{translate 'Session is stopped' scope='WahaSession'}}
        </div>
    {{else ifEqual status 'STARTING'}}
        <div class="alert alert-info" style="margin: 0;">
            <span class="fas fa-spinner fa-spin"></span>
            {{translate 'Session is starting' scope='WahaSession'}}
        </div>
    {{else ifEqual status 'FAILED'}}
        <div class="alert alert-danger" style="margin: 0;">
            <span class="fas fa-times-circle"></span>
            {{translate 'Session failed' scope='WahaSession'}}
        </div>
    {{else}}
        <span class="text-muted">-</span>
    {{/ifEqual}}
{{/if}}

