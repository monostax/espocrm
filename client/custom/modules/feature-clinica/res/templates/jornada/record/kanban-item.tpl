<style>
/* Jornada Kanban Card Styles */
.jornada-card {
    background: #fff;
    border-radius: 12px;
    padding: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 1px solid #e5e7eb;
    position: relative;
}

.jornada-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
    border-color: #d1d5db;
}

.jornada-card:active {
    transform: translateY(0);
}

/* Card Header */
.jornada-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
}

/* Jornada Info */
.jornada-info {
    flex: 1;
    min-width: 0;
    overflow: hidden;
}

.jornada-nome {
    font-weight: 600;
    color: #111827;
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 2px;
}

.jornada-paciente {
    font-size: 11px;
    color: #6b7280;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.jornada-paciente i {
    font-size: 10px;
    margin-right: 4px;
}

/* Info Section */
.jornada-info-section {
    margin-bottom: 10px;
}

.jornada-info-row {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 4px;
}

.jornada-info-row:last-child {
    margin-bottom: 0;
}

.jornada-info-row i {
    font-size: 11px;
    color: #9ca3af;
    width: 14px;
    text-align: center;
}

.jornada-info-row span {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Card Footer */
.jornada-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #f3f4f6;
}

/* Status Badge */
.jornada-status {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 3px 8px;
    border-radius: 9999px;
}

.jornada-status.status-em-andamento {
    background: #dbeafe;
    color: #1d4ed8;
}

.jornada-status.status-pausada {
    background: #fef3c7;
    color: #92400e;
}

.jornada-status.status-concluida {
    background: #d1fae5;
    color: #047857;
}

.jornada-status.status-abandonada {
    background: #fee2e2;
    color: #b91c1c;
}

.jornada-status.status-cancelada {
    background: #fee2e2;
    color: #b91c1c;
}

/* Meta Info */
.jornada-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 11px;
    color: #9ca3af;
}

.jornada-meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
}

.jornada-meta-item i {
    font-size: 10px;
}

/* Date styles */
.jornada-date {
    font-size: 11px;
    color: #6b7280;
}

.jornada-date.is-overdue {
    color: #dc2626;
    font-weight: 500;
}

.jornada-date.is-soon {
    color: #f59e0b;
    font-weight: 500;
}

/* Menu Container Override */
.jornada-card .item-menu-container {
    position: absolute;
    top: 8px;
    right: 8px;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.jornada-card:hover .item-menu-container {
    opacity: 1;
}
</style>

<div class="jornada-card" data-id="{{id}}">
    {{#unless rowActionsDisabled}}
    <div class="item-menu-container">{{{itemMenu}}}</div>
    {{/unless}}

    <div class="jornada-card-header">
        <div class="jornada-info">
            <div class="jornada-nome">{{nome}}</div>
            {{#if pacienteName}}
            <div class="jornada-paciente">
                <i class="ti ti-user"></i>{{pacienteName}}
            </div>
            {{/if}}
        </div>
    </div>

    <div class="jornada-info-section">
        {{#if hasPrograma}}
        <div class="jornada-info-row">
            <i class="ti ti-route"></i>
            <span>{{programaName}}</span>
        </div>
        {{/if}}

        {{#if hasProfissional}}
        <div class="jornada-info-row">
            <i class="ti ti-user-check"></i>
            <span>{{profissionalName}}</span>
        </div>
        {{/if}}

        {{#if hasUnidade}}
        <div class="jornada-info-row">
            <i class="fas fa-building"></i>
            <span>{{unidadeName}}</span>
        </div>
        {{/if}}

        {{#if hasConvenio}}
        <div class="jornada-info-row">
            <i class="fas fa-file-medical"></i>
            <span>{{convenioName}}</span>
        </div>
        {{/if}}
    </div>

    <div class="jornada-card-footer">
        {{#if dataInicioFormatted}}
        <span class="jornada-date">
            <i class="fas fa-calendar-alt"></i>
            {{dataInicioFormatted}}
        </span>
        {{else}}
        <span></span>
        {{/if}}

        <span class="jornada-status {{statusStyle}}">{{statusLabel}}</span>
    </div>
</div>
