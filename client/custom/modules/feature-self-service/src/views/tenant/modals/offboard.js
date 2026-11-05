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
 * Confirms a workspace offboarding: says what is removed and asks for the
 * slug. POST SelfServiceOffboarding/action/start, then triggers `started`
 * with the offboarding id.
 */
import ModalView from 'views/modal';

export default class extends ModalView {

    backdrop = true

    // language=Handlebars
    templateContent = `
        <p class="complex-text">{{viewObject.introText}}</p>
        <p>{{viewObject.slugText}}</p>
        <input type="text" class="form-control" data-name="slug" autocomplete="off" spellcheck="false">
    `

    setup() {
        this.headerText = this.translate('Offboard workspace', 'labels', 'Tenant');

        this.introText = this.translate('offboardIntro', 'messages', 'Tenant')
            .replace('{name}', this.model.get('name'));

        this.slugText = this.translate('offboardTypeSlug', 'messages', 'Tenant')
            .replace('{slug}', this.model.get('slug'));

        this.buttonList = [
            {
                name: 'offboard',
                label: this.translate('Offboard', 'labels', 'Tenant'),
                style: 'danger',
                disabled: true,
                onClick: () => this.actionOffboard(),
            },
            {
                name: 'cancel',
                label: 'Cancel',
            },
        ];
    }

    afterRender() {
        this.slugInput = this.element.querySelector('[data-name="slug"]');

        this.slugInput.addEventListener('input', () => {
            this.slugInput.value.trim() === this.model.get('slug') ?
                this.enableButton('offboard') :
                this.disableButton('offboard');
        });

        this.slugInput.focus();
    }

    async actionOffboard() {
        await this.disableButton('offboard');

        let result;

        try {
            result = await Espo.Ajax.postRequest('SelfServiceOffboarding/action/start', {
                tenantId: this.model.id,
                slug: this.slugInput.value.trim(),
            });
        } catch (xhr) {
            const reason = xhr.getResponseHeader ? xhr.getResponseHeader('X-Status-Reason') : null;
            const messages = {
                SLUG_MISMATCH: 'offboardSlugMismatch',
                OPEN_SUBSCRIPTION: 'offboardOpenSubscription',
            };

            if (messages[reason]) {
                xhr.errorIsHandled = true;
                Espo.Ui.error(this.translate(messages[reason], 'messages', 'Tenant'));
            }

            await this.enableButton('offboard');

            return;
        }

        Espo.Ui.success(this.translate('offboardStarted', 'messages', 'Tenant'));
        this.trigger('started', result.id);
        this.close();
    }
}
