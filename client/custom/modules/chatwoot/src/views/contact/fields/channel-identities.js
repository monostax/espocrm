/**
 * Field view rendering the Contact's channel identities
 * (ContactChannelIdentity rows) like the built-in phone field block.
 *
 * Data comes from the `channelIdentitiesData` virtual attribute populated by
 * Espo\Modules\Chatwoot\Classes\FieldProcessing\Contact\ChannelIdentitiesLoader.
 *
 * Edit mode allows adding/removing identities and switching the primary one
 * per channel, mirroring the built-in phone field UX. Persistence and
 * conflict rejection are handled by
 * Espo\Modules\Chatwoot\Hooks\Contact\ChannelIdentities.
 *
 * WhatsApp identities link to wa.me, Instagram identities link to the
 * profile page (when a username label is available).
 */

import BaseFieldView from 'views/fields/base';

const ICON_MAP = {
    whatsapp: 'fab fa-whatsapp',
    instagram: 'fab fa-instagram',
    telegram: 'fab fa-telegram-plane',
    facebook: 'fab fa-facebook',
    twitter: 'fab fa-twitter',
    sms: 'fas fa-sms',
    email: 'fas fa-envelope',
    web_widget: 'fas fa-globe',
    api: 'fas fa-code',
    line: 'fab fa-line',
    viber: 'fab fa-viber',
    other: 'fas fa-comment',
};

/** Channel types offered for manual entry. */
const EDITABLE_CHANNEL_TYPE_LIST = [
    'whatsapp',
    'instagram',
    'telegram',
    'facebook',
    'twitter',
    'sms',
    'email',
    'other',
];

class ChannelIdentitiesFieldView extends BaseFieldView {

    detailTemplate = 'chatwoot:contact/fields/channel-identities/detail'
    listTemplate = 'chatwoot:contact/fields/channel-identities/list'
    editTemplate = 'chatwoot:contact/fields/channel-identities/edit'

    /**
     * Working copy of items while in edit mode.
     *
     * @type {Array<Object>|null}
     */
    editItemList = null

    events = {
        /** @this ChannelIdentitiesFieldView */
        'click [data-action="addChannelIdentity"]': function () {
            this.addIdentity();
        },
        /** @this ChannelIdentitiesFieldView */
        'click [data-action="removeChannelIdentity"]': function (e) {
            this.removeIdentity(parseInt($(e.currentTarget).closest('[data-index]').attr('data-index')));
        },
        /** @this ChannelIdentitiesFieldView */
        'click [data-action="switchPrimary"]': function (e) {
            this.switchPrimary(parseInt($(e.currentTarget).closest('[data-index]').attr('data-index')));
        },
        /** @this ChannelIdentitiesFieldView */
        'change select[data-property-type="channelType"]': function () {
            this.trigger('change');
        },
        /** @this ChannelIdentitiesFieldView */
        'input input.channel-identity-source-id': function () {
            this.trigger('change');
        },
    }

    setup() {
        super.setup();

        const acl = this.getAcl();

        // Writes are gated server-side by ContactChannelIdentity scope ACL
        // (Hooks\Contact\ChannelIdentities). Mirror it here for UX.
        if (
            !acl.checkScope('ContactChannelIdentity', 'create') &&
            !acl.checkScope('ContactChannelIdentity', 'edit') &&
            !acl.checkScope('ContactChannelIdentity', 'delete')
        ) {
            this.setReadOnly(true);
        }

        this.on('mode-changed', () => {
            this.editItemList = null;
        });

        this.listenTo(this.model, 'change:' + this.name, () => {
            if (!this.isEditMode()) {
                this.editItemList = null;
            }
        });
    }

    data() {
        if (this.isEditMode()) {
            return {
                ...super.data(),
                itemList: this.getEditItemList().map((item, index) => this.prepareEditItem(item, index)),
            };
        }

        const rawList = this.model.get(this.name) || [];

        const itemList = rawList.map(item => this.prepareItem(item));

        return {
            ...super.data(),
            itemList,
            isEmpty: itemList.length === 0,
            valueIsSet: this.model.has(this.name),
        };
    }

    // -------------------------------------------------------------------
    // Edit mode
    // -------------------------------------------------------------------

    /**
     * @return {Array<Object>}
     */
    getEditItemList() {
        if (this.editItemList) {
            return this.editItemList;
        }

        this.editItemList = (this.model.get(this.name) || [])
            .map(item => ({
                id: item.id || null,
                channelType: item.channelType || 'other',
                sourceId: item.sourceId || '',
                label: item.label || null,
                isPrimary: !!item.isPrimary,
            }));

        if (!this.editItemList.length) {
            this.editItemList.push(this.createBlankItem());
        }

        return this.editItemList;
    }

    createBlankItem() {
        return {
            id: null,
            channelType: 'whatsapp',
            sourceId: '',
            label: null,
            isPrimary: false,
        };
    }

    prepareEditItem(item, index) {
        // Existing rows keep their channel type even if it is not
        // manually creatable (e.g. web_widget, api, line, viber).
        const typeList = EDITABLE_CHANNEL_TYPE_LIST.includes(item.channelType) ?
            EDITABLE_CHANNEL_TYPE_LIST :
            [item.channelType, ...EDITABLE_CHANNEL_TYPE_LIST];

        return {
            index: index,
            sourceId: item.sourceId,
            isPrimary: item.isPrimary,
            typeOptionDataList: typeList.map(type => ({
                value: type,
                selected: type === item.channelType,
                label: this.getLanguage()
                    .translateOption(type, 'channelType', 'ContactChannelIdentity'),
            })),
        };
    }

    /**
     * Read current input values from the DOM into the working item list.
     */
    syncItemsFromDom() {
        if (!this.editItemList || !this.isRendered()) {
            return;
        }

        this.$el.find('.channel-identity-edit-block').each((i, el) => {
            const $el = $(el);
            const index = parseInt($el.attr('data-index'));

            const item = this.editItemList[index];

            if (!item) {
                return;
            }

            const channelType = $el.find('select[data-property-type="channelType"]').val();
            const sourceId = ($el.find('input.channel-identity-source-id').val() || '').trim();

            if (channelType !== item.channelType) {
                // A row repurposed to another channel is a different
                // identity; drop the stale row linkage and label.
                item.id = null;
                item.label = null;
            }

            item.channelType = channelType;
            item.sourceId = sourceId;
            item.isPrimary = $el.find('button[data-action="switchPrimary"]').hasClass('active');
        });
    }

    addIdentity() {
        this.syncItemsFromDom();

        this.getEditItemList().push(this.createBlankItem());

        this.reRender().then(() => {
            this.$el.find('.channel-identity-edit-block')
                .last()
                .find('input.channel-identity-source-id')
                .focus();
        });

        this.trigger('change');
    }

    removeIdentity(index) {
        this.syncItemsFromDom();

        this.getEditItemList().splice(index, 1);

        this.reRender();
        this.trigger('change');
    }

    switchPrimary(index) {
        this.syncItemsFromDom();

        const itemList = this.getEditItemList();
        const item = itemList[index];

        if (!item) {
            return;
        }

        item.isPrimary = !item.isPrimary;

        if (item.isPrimary) {
            itemList.forEach((other, i) => {
                if (i !== index && other.channelType === item.channelType) {
                    other.isPrimary = false;
                }
            });
        }

        this.reRender();
        this.trigger('change');
    }

    fetch() {
        this.syncItemsFromDom();

        const itemList = this.getEditItemList()
            .filter(item => item.sourceId !== '')
            .map(item => ({
                id: item.id,
                channelType: item.channelType,
                sourceId: item.sourceId,
                label: item.label,
                isPrimary: item.isPrimary,
            }));

        return {
            [this.name]: itemList,
        };
    }

    // -------------------------------------------------------------------
    // Read mode
    // -------------------------------------------------------------------

    prepareItem(item) {
        const channelType = item.channelType || 'other';

        const prepared = {
            channelType: channelType,
            iconClass: ICON_MAP[channelType] || ICON_MAP.other,
            typeLabel: this.getLanguage()
                .translateOption(channelType, 'channelType', 'ContactChannelIdentity'),
            isPrimary: !!item.isPrimary,
            display: item.label || item.sourceId,
            secondary: null,
            url: null,
        };

        if (channelType === 'whatsapp') {
            // sourceId is an E.164 phone number (e.g. +5511999999999).
            prepared.display = item.sourceId;
            prepared.secondary = item.label && item.label !== item.sourceId ?
                item.label : null;

            const digits = (item.sourceId || '').replace(/\D/g, '');

            if (digits) {
                prepared.url = 'https://wa.me/' + digits;
            }

            return prepared;
        }

        if (channelType === 'instagram') {
            // sourceId is the routable scoped user id (or a handle for
            // rows entered before the scoped id was observed); the handle
            // column holds the username. Legacy rows may carry a
            // handle-like value in label or sourceId instead.
            const handleLike = value => {
                const v = (value || '').replace(/^@/, '').trim();

                return /^[A-Za-z0-9._-]+$/.test(v) && !/^\d+$/.test(v) ? v : '';
            };

            const username = handleLike(item.handle) ||
                handleLike(item.label) ||
                handleLike(item.sourceId);

            if (username) {
                prepared.display = '@' + username;
                prepared.url = 'https://www.instagram.com/' + encodeURIComponent(username);
            } else {
                prepared.display = item.sourceId;
            }

            return prepared;
        }

        if (item.label && item.label !== item.sourceId) {
            prepared.secondary = item.sourceId;
        }

        return prepared;
    }
}

export default ChannelIdentitiesFieldView;
