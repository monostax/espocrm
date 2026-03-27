/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/
/**
 * A field view that displays ChatwootConversation records belonging to
 * the Contact linked to the current entity.
 *
 * This is designed for entities that do NOT have a direct link to
 * ChatwootConversation but DO have a contactId on the model (either via
 * a direct belongsTo or a ReadHook like PopulateContactFromPaciente).
 *
 * It fetches conversations using the Contact's relationship endpoint:
 *   GET Contact/{contactId}/chatwootConversations
 *
 * Usage in detail layout JSON:
 * {
 *     "tabBreak": true,
 *     "tabLabel": "$ChatwootConversations",
 *     "name": "contactConversationsTab",
 *     "rows": [
 *         [
 *             {
 *                 "name": "contactConversationsList",
 *                 "view": "chatwoot:views/contact/fields/contact-conversations-list",
 *                 "noLabel": true,
 *                 "span": 4,
 *                 "options": {
 *                     "contactIdAttribute": "contactId",
 *                     "layout": "listSmall",
 *                     "recordsPerPage": 10
 *                 }
 *             }
 *         ]
 *     ]
 * }
 */
import BaseFieldView from 'views/fields/base';
class ContactConversationsListFieldView extends BaseFieldView {
    // noinspection JSUnusedGlobalSymbols
    templateContent = `
        <div class="contact-conversations-list-field">
            <div class="panel panel-default panel-condensed{{#if isCollapsed}} is-collapsed{{/if}}">
                <div class="panel-heading">
                    <div class="pull-right btn-group panel-actions-container">
                        {{#if showViewListButton}}
                        <button
                            type="button"
                            class="btn btn-default btn-sm panel-action action"
                            data-action="viewRelatedList"
                            title="{{translate 'View List'}}"
                        ><span class="fas fa-list"></span></button>
                        {{/if}}
                    </div>
                    <h4 class="panel-title">
                        <span class="panel-collapse-chevron fas {{#if isCollapsed}}fa-chevron-right{{else}}fa-chevron-down{{/if}}"></span>
                        {{#if icon}}<span class="relationship-list-entity-icon {{icon}}"{{#if iconColor}} style="color: {{iconColor}}"{{/if}}></span> {{/if}}
                        <span class="relationship-list-title-text">{{title}}</span>
                    </h4>
                </div>
                <div class="panel-body{{#if isCollapsed}} hidden{{/if}}">
                    {{#if hasContactId}}
                        {{#if hasAccess}}
                        <div class="list-container"></div>
                        {{else}}
                        <div class="text-muted" style="padding: 10px 0;">
                            {{noAccessMessage}}
                        </div>
                        {{/if}}
                    {{else}}
                    <div class="text-muted" style="padding: 10px 0;">
                        {{noContactMessage}}
                    </div>
                    {{/if}}
                </div>
            </div>
        </div>
    `;
    /** @type {string} */
    contactIdAttribute = 'contactId';
    /** @type {string} */
    layoutName = 'listForContactPanel';
    /** @type {number} */
    recordsPerPage = 10;
    /** @type {string} */
    foreignEntityType = 'ChatwootConversation';
    /** @type {boolean} */
    isCollapsed = false;
    /** @type {string|null} */
    collapseStorageKey = null;
    events = {
        'click .panel-heading': function (e) {
            if (
                $(e.target).closest(
                    '.panel-actions-container, .action, a, button, input, textarea, select, .dropdown-menu'
                ).length
            ) {
                return;
            }
            this.toggleCollapsed();
        },
        'click [data-action="viewRelatedList"]': function (e) {
            e.preventDefault();
            e.stopPropagation();
            this.actionViewRelatedList();
        },
    };
    data() {
        const contactId = this.model.get(this.contactIdAttribute);
        const hasAccess = this.getAcl().check('Contact', 'read')
            && this.getAcl().check('ChatwootConversation', 'read');
        return {
            showViewListButton: !!contactId && hasAccess,
            hasContactId: !!contactId,
            hasAccess: hasAccess,
            title: this.getTitle(),
            icon: this.getIcon(),
            iconColor: this.getIconColor(),
            isCollapsed: this.isCollapsed,
            noContactMessage: this.translate('No Contact linked', 'labels', this.model.entityType),
            noAccessMessage: this.translate('Access denied', 'messages'),
        };
    }
    getTitle() {
        return this.translate('ChatwootConversation', 'scopeNamesPlural');
    }
    getIcon() {
        return this.getMetadata().get([
            'clientDefs', this.foreignEntityType, 'iconClass',
        ]) || null;
    }
    getIconColor() {
        return this.getMetadata().get([
            'clientDefs', this.foreignEntityType, 'color',
        ]) || null;
    }
    /**
     * Override fetch to return empty object.
     * Display-only view, no field data to save.
     */
    fetch() {
        return {};
    }
    getAttributeList() {
        return [];
    }
    validate() {
        return false;
    }
    setup() {
        super.setup();
        this.contactIdAttribute =
            this.options.contactIdAttribute
            || this.options.defs?.params?.contactIdAttribute
            || 'contactId';
        this.layoutName =
            this.options.layout
            || this.options.defs?.params?.layout
            || 'listSmall';
        this.recordsPerPage =
            this.options.recordsPerPage
            || this.options.defs?.params?.recordsPerPage
            || 10;
        this.setupCollapsedState();
    }
    setupCollapsedState() {
        const userId = this.getUser().id || 'anonymous';
        const panelKey = this.name || this.options.defs?.name || 'contactConversations';
        this.collapseStorageKey =
            `record-panel-collapse:${userId}:${this.model.entityType}:contact-conversations:${panelKey}`;
        this.isCollapsed = this.getCollapsedStored();
    }
    getCollapsedStored() {
        try {
            return localStorage.getItem(this.collapseStorageKey) === 'true';
        } catch (e) {
            return false;
        }
    }
    setCollapsedStored(value) {
        try {
            localStorage.setItem(this.collapseStorageKey, value ? 'true' : 'false');
        } catch (e) {}
    }
    toggleCollapsed() {
        this.isCollapsed = !this.isCollapsed;
        this.setCollapsedStored(this.isCollapsed);
        this.applyCollapsedState();
    }
    applyCollapsedState() {
        if (!this.isRendered()) {
            return;
        }
        const $panel = this.$el.find('.panel').first();
        if (!$panel.length) {
            return;
        }
        $panel.toggleClass('is-collapsed', this.isCollapsed);
        $panel.find('> .panel-body').toggleClass('hidden', this.isCollapsed);
        const $icon = $panel.find('> .panel-heading .panel-collapse-chevron').first();
        if ($icon.length) {
            $icon
                .toggleClass('fa-chevron-down', !this.isCollapsed)
                .toggleClass('fa-chevron-right', this.isCollapsed);
        }
    }
    afterRender() {
        this.applyCollapsedState();
        const contactId = this.model.get(this.contactIdAttribute);
        const hasAccess = this.getAcl().check('Contact', 'read')
            && this.getAcl().check('ChatwootConversation', 'read');
        if (contactId && hasAccess && this.model.id) {
            this.setupConversationsList(contactId);
        }
    }
    /**
     * Fetch and render conversations for the given contactId.
     *
     * @param {string} contactId
     */
    setupConversationsList(contactId) {
        const url = `Contact/${contactId}/chatwootConversations`;
        this.clearView('list');
        this.getCollectionFactory().create(this.foreignEntityType, (collection) => {
            collection.url = collection.urlRoot = url;
            collection.maxSize = this.recordsPerPage;
            collection.data.select = this.getSelectAttributes();
            this.collection = collection;
            this.createView('list', 'views/record/list', {
                selector: '.list-container',
                collection: collection,
                layoutName: this.layoutName,
                type: 'listSmall',
                selectable: true,
                checkboxes: false,
                rowActionsView: false,
                buttonsDisabled: true,
                displayTotalCount: false,
                skipBuildRows: true,
                pagination: collection.maxSize < 200,
            }, (view) => {
                this.listenTo(view, 'select', (model) => {
                    this.openConversationDrawer(model);
                });

                collection.fetch().then(() => {
                    view.render();
                });
            });
        });
    }
    /**
     * Open the Chatwoot conversation drawer for the selected conversation.
     *
     * @param {Object} model - The ChatwootConversation Backbone model.
     */
    openConversationDrawer(model) {
        this.createView(
            'conversationDrawer',
            'chatwoot:views/chatwoot-conversation/modals/conversation-drawer',
            {
                chatwootConversationId: model.get('chatwootConversationId'),
                chatwootAccountIdExternal: model.get('chatwootAccountIdExternal'),
                contactName: model.get('contactDisplayName') || model.get('name'),
                recordId: model.id,
            },
            (view) => {
                view.render();

                this.listenToOnce(view, 'close', () => {
                    if (this.collection) {
                        this.collection.fetch();
                    }
                });
            }
        );
    }

    /**
     * Get select attributes from the layout to optimize the API query.
     *
     * @returns {string|undefined}
     */
    getSelectAttributes() {
        const layoutDefs = this.getMetadata().get([
            'clientDefs', this.foreignEntityType, 'layouts', this.layoutName,
        ]);
        // Don't restrict select if we can't read the layout metadata
        if (!layoutDefs || !Array.isArray(layoutDefs)) {
            return undefined;
        }
        return undefined;
    }
    /**
     * Open a modal with the full conversation list for the Contact.
     */
    actionViewRelatedList() {
        const contactId = this.model.get(this.contactIdAttribute);
        if (!contactId) {
            return;
        }
        const url = `Contact/${contactId}/chatwootConversations`;
        Espo.Ui.notify(' ... ');
        const viewName = this.getMetadata().get([
            'clientDefs', this.foreignEntityType, 'modalViews', 'relatedList',
        ]) || 'views/modals/related-list';
        this.createView('dialog', viewName, {
            scope: this.foreignEntityType,
            title: this.getTitle(),
            url: url,
            createDisabled: true,
            selectDisabled: true,
            rowActionsView: 'views/record/row-actions/view-only',
        }, (view) => {
            view.render();
            Espo.Ui.notify(false);
            this.listenToOnce(view, 'close', () => {
                if (this.collection) {
                    this.collection.fetch();
                }
            });
        });
    }
}
export default ContactConversationsListFieldView;