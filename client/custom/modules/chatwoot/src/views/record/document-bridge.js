/************************************************************************
 * This file is part of Monostax.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 ************************************************************************/

import View from "view";
import {endpoint} from "feature-record-knowledge:content";

/** A canonical record overview, using the shared Notion-style editor. */
export default class RecordDocumentBridgeView extends View {
    templateContent = `
        {{#ifEqual bridgeState 'loading'}}
            <div class="text-center margin-top" role="status"><span class="fas fa-spinner fa-spin"></span></div>
        {{/ifEqual}}
        {{#ifEqual bridgeState 'error'}}
            <div class="text-danger margin-top" role="alert">{{translate 'knowledgeUnavailable' category='messages'}}</div>
            <button type="button" class="btn btn-default margin-top" data-action="reload">{{translate 'Refresh'}}</button>
        {{/ifEqual}}
        {{#ifEqual bridgeState 'ready'}}
            <div class="margin-bottom">
                {{#if editable}}
                    <button type="button" class="btn btn-primary" data-action="save" disabled>{{translate 'Save'}}</button>
                {{/if}}
                <button type="button" class="btn btn-default" data-action="reload">{{translate 'Refresh'}}</button>
            </div>
            <div class="text-danger margin-bottom" role="alert" data-name="error"></div>
            <div class="field" data-name="body">{{{body}}}</div>
        {{/ifEqual}}
    `;

    bridgeState = "loading";
    requestId = 0;

    setup() {
        this.addHandler("click", '[data-action="save"]', () => this.actionSave());
        this.addHandler("click", '[data-action="reload"]', () => {
            if (!this.saving) this.loadDocument();
        });
        this.messageHandler = event => this.onContext(event);
        window.addEventListener("message", this.messageHandler);
        window.parent.postMessage("chatwoot-dashboard-app:fetch-info", "*");
    }

    onRemove() {
        this.requestId += 1;
        window.removeEventListener("message", this.messageHandler);
    }

    data() {
        return {bridgeState: this.bridgeState, editable: this.document?.editable};
    }

    getRecordIdentity(context) {
        return context?.record;
    }

    onContext(event) {
        if (event.source !== window.parent || typeof event.data !== "string") return;
        let message;
        try {
            message = JSON.parse(event.data);
        } catch {
            return;
        }
        if (message?.event !== "appContext") return;
        const record = this.getRecordIdentity(message.data);
        if (!record?.id || !record.entityType) return;
        const id = String(record.id);
        if (id === this.parentModel?.id && record.entityType === this.parentModel.entityType) return;
        this.parentModel = {entityType: record.entityType, id};
        this.loadDocument();
    }

    async loadDocument() {
        const requestId = ++this.requestId;
        this.clearView("body");
        this.bridgeState = "loading";
        if (this.isRendered()) this.reRender();

        try {
            const document = await Espo.Ajax.getRequest(endpoint("overview", this.parentModel));
            const model = await this.getModelFactory().create("Document");
            if (requestId !== this.requestId) return;
            this.document = document;
            model.set({
                id: document.documentId,
                contentType: "Page",
                body: document.body || "",
                bodyEditorState: document.bodyEditorState,
                bodyFormat: "Markdown",
                bodyAuthoringMode: document.bodyAuthoringMode || "Markdown",
                knowledgeRecordType: this.parentModel.entityType,
                knowledgeRecordId: this.parentModel.id,
            });
            this.bridgeState = "ready";
            await this.whenRendered();
            await this.reRender();
            if (requestId !== this.requestId) return;

            const field = await this.createView("body", "feature-document-pages:views/document/fields/body", {
                model,
                mode: document.editable ? "edit" : "detail",
                selector: '.field[data-name="body"]',
                defs: {name: "body", params: {minHeight: 360}},
            });
            if (requestId !== this.requestId) return;
            await field.render();
            this.el.querySelector('[data-action="save"]')?.removeAttribute("disabled");
        } catch {
            if (requestId !== this.requestId) return;
            this.clearView("body");
            this.bridgeState = "error";
            if (this.isRendered()) this.reRender();
        }
    }

    async actionSave() {
        const field = this.getView("body");
        if (this.saving || !this.document?.editable || !field?.isRendered()) return;
        const requestId = this.requestId;
        this.saving = true;
        this.el.querySelector('[data-name="error"]').textContent = "";
        this.el.querySelectorAll('[data-action="save"], [data-action="reload"]').forEach(button => {
            button.disabled = true;
        });
        try {
            const {body, bodyEditorState} = field.fetch();
            const initialEditorState = field.contentState(field.readEditorStateJSON());
            const document = await Espo.Ajax.putRequest(endpoint("overview", this.parentModel), {body, bodyEditorState},
                {headers: {"X-Version-Number": this.document.versionNumber}});
            if (requestId !== this.requestId) return;
            this.document = document;
            // Advance the saved baseline without remounting or losing edits made
            // while the request was in flight.
            field.model.set({body: document.body, bodyEditorState: document.bodyEditorState,
                bodyAuthoringMode: document.bodyAuthoringMode}, {silent: true});
            field.initialEditorState = initialEditorState;
            Espo.Ui.success(this.translate("Saved"));
        } catch (error) {
            if (requestId !== this.requestId) return;
            this.el.querySelector('[data-name="error"]').textContent = this.translate(
                error.status === 409 ? "knowledgeConflict" : "knowledgeUnavailable", "messages");
        } finally {
            this.saving = false;
            if (requestId === this.requestId) {
                this.el.querySelectorAll('[data-action="save"], [data-action="reload"]').forEach(button => {
                    button.disabled = false;
                });
            }
        }
    }
}
