<style>
/* Orcamento Kanban Card Styles */
.orcamento-card {
    background: #fff;
    border-radius: 12px;
    padding: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 1px solid #e5e7eb;
    position: relative;
}

.orcamento-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
    border-color: #d1d5db;
}

.orcamento-card:active {
    transform: translateY(0);
}

/* Card Header */
.orcamento-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
}

/* Orcamento Info */
.orcamento-info {
    flex: 1;
    min-width: 0;
    overflow: hidden;
}

.orcamento-numero {
    font-weight: 600;
    color: #111827;
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 2px;
}

.orcamento-paciente {
    font-size: 11px;
    color: #6b7280;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.orcamento-paciente i {
    font-size: 10px;
    margin-right: 4px;
}

/* Value Badge */
.orcamento-value {
    font-size: 13px;
    font-weight: 700;
    color: #059669;
    flex-shrink: 0;
    margin-left: auto;
    background: #d1fae5;
    padding: 4px 10px;
    border-radius: 8px;
}

/* Info Section */
.orcamento-info-section {
    margin-bottom: 10px;
}

.orcamento-info-row {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 4px;
}

.orcamento-info-row:last-child {
    margin-bottom: 0;
}

.orcamento-info-row i {
    font-size: 11px;
    color: #9ca3af;
    width: 14px;
    text-align: center;
}

.orcamento-info-row span {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Card Footer */
.orcamento-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #f3f4f6;
}

/* Status Badge */
.orcamento-status {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 3px 8px;
    border-radius: 9999px;
}

.orcamento-status.status-rascunho {
    background: #f3f4f6;
    color: #374151;
}

.orcamento-status.status-enviado {
    background: #dbeafe;
    color: #1d4ed8;
}

.orcamento-status.status-aprovado {
    background: #d1fae5;
    color: #047857;
}

.orcamento-status.status-expirado {
    background: #fef3c7;
    color: #92400e;
}

.orcamento-status.status-recusado {
    background: #fee2e2;
    color: #b91c1c;
}

/* Meta Info */
.orcamento-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 11px;
    color: #9ca3af;
}

.orcamento-meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
}

.orcamento-meta-item i {
    font-size: 10px;
}

/* Date styles */
.orcamento-date {
    font-size: 11px;
    color: #6b7280;
}

.orcamento-date.is-overdue {
    color: #dc2626;
    font-weight: 500;
}

.orcamento-date.is-soon {
    color: #f59e0b;
    font-weight: 500;
}

/* Menu Container Override */
.orcamento-card .item-menu-container {
    position: absolute;
    top: 8px;
    right: 8px;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.orcamento-card:hover .item-menu-container {
    opacity: 1;
}
</style>

<div class="orcamento-card" data-id="{{id}}">
    {{#unless rowActionsDisabled}}
    <div class="item-menu-container">{{{itemMenu}}}</div>
    {{/unless}}

    <div class="orcamento-card-header">
        <div class="orcamento-info">
            <div class="orcamento-numero">{{numero}}</div>
            {{#if pacienteName}}
            <div class="orcamento-paciente">
                <i class="ti ti-user"></i>{{pacienteName}}
            </div>
            {{/if}}
        </div>

        {{#if valorFormatted}}
        <div class="orcamento-value">{{valorFormatted}}</div>
        {{/if}}
    </div>

    <div class="orcamento-info-section">
        {{#if hasProfissional}}
        <div class="orcamento-info-row">
            <i class="ti ti-user-check"></i>
            <span>{{profissionalName}}</span>
        </div>
        {{/if}}

        {{#if hasUnidade}}
        <div class="orcamento-info-row">
            <i class="fas fa-building"></i>
            <span>{{unidadeName}}</span>
        </div>
        {{/if}}

        {{#if hasConvenio}}
        <div class="orcamento-info-row">
            <i class="fas fa-file-medical"></i>
            <span>{{convenioName}}</span>
        </div>
        {{/if}}
    </div>

    <div class="orcamento-card-footer">
        {{#if dataValidadeFormatted}}
        <span class="orcamento-date {{dataValidadeClass}}">
            <i class="fas fa-calendar"></i>
            {{dataValidadeFormatted}}
        </span>
        {{else}}
        <span></span>
        {{/if}}

        <span class="orcamento-status {{statusStyle}}">{{statusLabel}}</span>
    </div>
</div>
