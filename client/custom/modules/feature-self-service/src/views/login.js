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
 * The login page plus self-service signup (clientDefs.App.loginView).
 *
 * Signup: form → emailed code → workspace creation → login with a one-time
 * ticket through the normal login request, then the usual Chatwoot redirect.
 * Shown only while the server reports self-service as enabled. Open it
 * directly with `?signup` or `#signup`.
 *
 * The template (feature-self-service:login) keeps every id and data-action
 * the parent view uses and adds places for the signup link and providers.
 * Styles: client/custom/modules/feature-self-service/css/login.css.
 *
 * The parent view declares `events` as a class field, so listeners here are
 * bound in afterRender instead.
 */
import LoginView from 'views/login';

const STORAGE_KEY = 'selfServiceSignup';
const POLL_MS = 2000;
const SLOW_AFTER_POLLS = 90;
const RESEND_SECONDS = 60;
const TERMS_URL = 'https://monostax.com.br/termos';
const PRIVACY_URL = 'https://monostax.com.br/privacidade';

const PROVIDER_LABELS = {google: 'continueWithGoogle', microsoft: 'continueWithMicrosoft'};

// Brand marks inline: the theme ships no Font Awesome brand glyphs.
const PROVIDER_ICONS = {
    google: '<svg viewBox="0 0 18 18" aria-hidden="true">' +
        '<path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/>' +
        '<path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.83.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/>' +
        '<path fill="#FBBC05" d="M3.97 10.72A5.41 5.41 0 0 1 3.68 9c0-.6.1-1.18.29-1.72V4.95H.96A9 9 0 0 0 0 9c0 1.45.35 2.83.96 4.05l3.01-2.33z"/>' +
        '<path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/>' +
        '</svg>',
    microsoft: '<svg viewBox="0 0 21 21" aria-hidden="true">' +
        '<rect x="1" y="1" width="9" height="9" fill="#F25022"/><rect x="11" y="1" width="9" height="9" fill="#7FBA00"/>' +
        '<rect x="1" y="11" width="9" height="9" fill="#00A4EF"/><rect x="11" y="11" width="9" height="9" fill="#FFB900"/>' +
        '</svg>',
};

const escape = value => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

export default class extends LoginView {

    template = 'feature-self-service:login'

    afterRender() {
        super.afterRender();

        this.selfServicePollTimer = null;
        this.selfServiceContainer = this.element.querySelector('[data-role="self-service"]');

        if (!this.selfServiceContainer) {
            return;
        }

        Espo.Ajax.getRequest('SelfService/config')
            .then(config => {
                if (config.state !== 'enabled' || this.isRemoved()) {
                    return;
                }

                this.selfServiceProviders = config.providers || [];
                this.selfServiceAddLink();

                if (this.selfServiceRestore()) {
                    return;
                }

                if (/[?&]signup\b/.test(window.location.search) || window.location.hash === '#signup') {
                    this.selfServiceShowForm();
                }
            })
            .catch(xhr => {
                if (xhr) {
                    xhr.errorIsHandled = true;
                }
            });
    }

    onRemove() {
        clearTimeout(this.selfServicePollTimer);
        clearInterval(this.selfServiceResendTimer);

        super.onRemove?.();
    }

    selfServiceText(key) {
        return this.translate(key, 'selfService', 'Global');
    }

    /**
     * The providers under the login form and "New here? Create a free account".
     */
    selfServiceAddLink() {
        const social = this.element.querySelector('[data-role="self-service-social"]');
        const cta = this.element.querySelector('[data-role="self-service-cta"]');

        if (social && this.selfServiceProviders.length) {
            social.innerHTML = this.selfServiceSocialButtons('after');
            social.classList.remove('hidden');
            this.selfServiceBindSocial(social);
        }

        if (!cta) {
            return;
        }

        cta.innerHTML = this.selfServiceSwitch('newHere', 'selfServiceSignup', this.selfServiceText('createAccount'));
        cta.classList.remove('hidden');
        cta.querySelector('[data-action="selfServiceSignup"]').addEventListener('click', () => this.selfServiceShowForm());
    }

    /**
     * "Continue with Google / Microsoft" for the providers the server has,
     * with the "or" divider on the side of the form they sit next to.
     *
     * @param {'before'|'after'} placement Where the buttons sit relative to the form.
     */
    selfServiceSocialButtons(placement) {
        if (!this.selfServiceProviders?.length) {
            return '';
        }

        const divider = `<div class="login-divider" role="separator"><span>${escape(this.selfServiceText('or'))}</span></div>`;
        const buttons = `<div class="login-social">${this.selfServiceProviders.map(provider =>
            `<button type="button" class="login-button" data-provider="${escape(provider)}">
                ${PROVIDER_ICONS[provider] || ''}<span>${escape(this.selfServiceText(PROVIDER_LABELS[provider] || provider))}</span>
            </button>`).join('')}</div>`;

        return placement === 'before' ? buttons + divider : divider + buttons;
    }

    selfServiceBindSocial(container) {
        container.querySelectorAll('[data-provider]').forEach(button =>
            button.addEventListener('click', () => this.selfServiceSocial(button.dataset.provider)));
    }

    /**
     * Sign in with a provider in a popup that returns to our /oauth/callback/.
     * The nonce stays in this tab, so a code started elsewhere can't log it in.
     */
    selfServiceSocial(provider) {
        const nonce = (crypto.randomUUID() + crypto.randomUUID()).replace(/-/g, '');
        // Opened in the click handler, so the browser doesn't block it.
        const popup = window.open('about:blank', 'SelfServiceOAuth', 'location=0,status=0,width=520,height=720');

        Espo.Ajax.postRequest('SelfService/oauth/start', {provider, nonce})
            .then(start => this.selfServiceWaitForCode(popup, start.url))
            .then(callback => {
                Espo.Ui.notifyWait();

                return Espo.Ajax.postRequest('SelfService/oauth/finish', {provider, nonce, ...callback});
            })
            .then(result => {
                Espo.Ui.notify(false);

                if (result.status === 'ready' && result.ticket) {
                    this.selfServiceLogin(result.ticket);

                    return;
                }

                this.selfServiceShowDetails(result);
            })
            .catch(xhr => {
                if (popup && !popup.closed) {
                    popup.close();
                }

                // null: the visitor closed the popup or the provider refused.
                // A notice, since the form's error line is not next to these buttons.
                if (xhr) {
                    this.selfServiceError(xhr, null, false);
                }
            });
    }

    selfServiceWaitForCode(popup, url) {
        if (!popup) {
            return Promise.reject(null);
        }

        popup.location.href = url;

        return new Promise((resolve, reject) => {
            const timer = setInterval(() => {
                if (popup.closed) {
                    clearInterval(timer);
                    reject(null);

                    return;
                }

                let href;

                try {
                    // Throws while the popup is on the provider's origin.
                    href = popup.location.href;
                } catch (e) {
                    return;
                }

                if (!href || !href.startsWith(window.location.origin)) {
                    return;
                }

                clearInterval(timer);

                const params = new URL(href).searchParams;

                popup.close();

                params.get('code') && params.get('state') ?
                    resolve({code: params.get('code'), state: params.get('state')}) :
                    reject(null);
            }, 300);
        });
    }

    /**
     * A provider confirmed an email that has no account yet: ask only for
     * what it can't tell us.
     */
    selfServiceShowDetails(result) {
        this.selfServiceRender(
            `<form data-role="self-service-details" class="login-form" novalidate>
                ${this.selfServiceHeader('detailsTitle', this.selfServiceEmphasis('detailsSignedInAs', '{email}', result.email))}
                ${this.selfServiceField('phone', 'tel', 'tel', 'maxlength="20" placeholder="(11) 91234-5678"')}
                ${this.selfServiceField('company', 'text', 'organization', 'maxlength="100"')}
                ${this.selfServiceTermsCheckbox(false)}
                ${this.selfServiceActions('submit')}
            </form>
            ${this.selfServiceSwitch(null, 'showLogin', this.selfServiceText('back'))}`
        );

        const form = this.selfServiceContainer.querySelector('form');

        this.selfServiceContainer.querySelector('[data-action="showLogin"]')
            .addEventListener('click', () => this.selfServiceShowLogin());
        form.addEventListener('submit', event => {
            event.preventDefault();

            const button = form.querySelector('[type="submit"]');
            button.disabled = true;
            Espo.Ui.notifyWait();

            Espo.Ajax.postRequest('SelfService/oauth/complete', {
                identity: result.identity,
                phone: form.querySelector('[name="phone"]').value,
                company: form.querySelector('[name="company"]').value,
                acceptTerms: form.querySelector('[name="acceptTerms"]').checked,
                language: this.getConfig().get('language') === 'en_US' ? 'en_US' : 'pt_BR',
            })
                .then(started => {
                    Espo.Ui.notify(false);
                    this.selfServiceSave({
                        signupId: started.signupId,
                        token: started.token,
                        email: result.email,
                        verified: true,
                    });
                    this.selfServiceProgress(started, 0);
                })
                .catch(xhr => {
                    button.disabled = false;
                    this.selfServiceError(xhr);
                });
        });

        form.querySelector('[name="phone"]').focus();
    }

    selfServiceTermsCheckbox(checked) {
        return `<label class="login-checkbox">
            <input type="checkbox" name="acceptTerms" ${checked ? 'checked' : ''}>
            <span>
                ${escape(this.selfServiceText('acceptTermsPrefix'))}
                <a href="${TERMS_URL}" target="_blank" rel="noopener">${escape(this.selfServiceText('terms'))}</a>
                ${escape(this.selfServiceText('and'))}
                <a href="${PRIVACY_URL}" target="_blank" rel="noopener">${escape(this.selfServiceText('privacy'))}</a>
            </span>
        </label>`;
    }

    /**
     * A step's title and an optional description, given as HTML.
     */
    selfServiceHeader(titleKey, descriptionHtml) {
        return `<header class="login-header">
            <h1 class="login-title">${escape(this.selfServiceText(titleKey))}</h1>
            ${descriptionHtml ? `<p class="login-description">${descriptionHtml}</p>` : ''}
        </header>`;
    }

    /**
     * A text with one value in bold, such as the email a code went to.
     */
    selfServiceEmphasis(key, placeholder, value) {
        return escape(this.selfServiceText(key)).replace(placeholder, () => `<strong>${escape(value)}</strong>`);
    }

    /**
     * A labeled input; the hint, when there is one, sits under it.
     */
    selfServiceField(name, type, autocomplete, attributes, value, hintKey) {
        const id = `self-service-${name}`;
        const hint = hintKey ?
            `<p class="login-hint" id="${id}-hint">${escape(this.selfServiceText(hintKey))}</p>` : '';

        return `<div class="login-field">
            <label for="${id}" class="login-label">${escape(this.selfServiceText(name))}</label>
            <input type="${type}" id="${id}" name="${name}" class="form-control"
                autocomplete="${autocomplete}" value="${escape(value)}"
                ${hintKey ? `aria-describedby="${id}-hint"` : ''} ${attributes || ''}>
            ${hint}
        </div>`;
    }

    /**
     * The step's one primary action, with the error line under it so a
     * failure never moves the button that was just pressed.
     */
    selfServiceActions(labelKey) {
        return `<button type="submit" class="login-button login-button-primary">${escape(this.selfServiceText(labelKey))}</button>
            <p class="login-error" role="alert" hidden></p>`;
    }

    /**
     * The line under the card's content that leads to the other path.
     */
    selfServiceSwitch(promptKey, action, label) {
        const prompt = promptKey ? `<span>${escape(this.selfServiceText(promptKey))}</span> ` : '';

        return `<p class="login-switch">${prompt}<button type="button" class="login-text-link login-text-strong"
            data-action="${action}">${escape(label)}</button></p>`;
    }

    selfServiceToggle(signupVisible) {
        this.element.querySelector('[data-role="login-main"]')?.classList.toggle('hidden', signupVisible);
        this.selfServiceContainer.classList.toggle('hidden', !signupVisible);
    }

    selfServiceShowLogin(email) {
        clearTimeout(this.selfServicePollTimer);
        clearInterval(this.selfServiceResendTimer);
        sessionStorage.removeItem(STORAGE_KEY);
        this.selfServiceToggle(false);

        if (email) {
            this.$username.val(email);
            this.$password.focus();
        }
    }

    selfServiceRender(html) {
        this.selfServiceToggle(true);
        this.selfServiceContainer.innerHTML = html;

        const form = this.selfServiceContainer.querySelector('form');

        // An error stays until the values that caused it change.
        form?.addEventListener('input', () => {
            const line = form.querySelector('.login-error');

            if (line && !line.hidden) {
                line.hidden = true;
                line.textContent = '';
            }
        });
    }

    selfServiceShowForm(values) {
        values = values || {};

        const field = (name, type, autocomplete, attributes, hintKey) =>
            this.selfServiceField(name, type, autocomplete, attributes, values[name], hintKey);

        this.selfServiceRender(
            `<form data-role="self-service-form" class="login-form" novalidate>
                ${this.selfServiceHeader('title', escape(this.selfServiceText('subtitle')))}
                ${this.selfServiceSocialButtons('before')}
                ${field('name', 'text', 'name', 'maxlength="100"')}
                ${field('email', 'email', 'email', 'maxlength="254" autocapitalize="off" spellcheck="false"')}
                ${field('phone', 'tel', 'tel', 'maxlength="20" placeholder="(11) 91234-5678"')}
                ${field('company', 'text', 'organization', 'maxlength="100"')}
                ${field('password', 'password', 'new-password', 'maxlength="128" minlength="8"', 'passwordHint')}
                ${this.selfServiceTermsCheckbox(values.acceptTerms)}
                ${this.selfServiceActions('submit')}
            </form>
            ${this.selfServiceSwitch('haveAccount', 'showLogin', this.logInText)}`
        );

        const form = this.selfServiceContainer.querySelector('form');

        this.selfServiceBindSocial(form);

        this.selfServiceContainer.querySelector('[data-action="showLogin"]')
            .addEventListener('click', () => this.selfServiceShowLogin());
        form.addEventListener('submit', event => {
            event.preventDefault();
            this.selfServiceSubmit(form);
        });

        form.querySelector('[name="name"]').focus();
    }

    selfServiceSubmit(form) {
        const value = name => form.querySelector(`[name="${name}"]`).value;
        const data = {
            name: value('name'),
            email: value('email').trim(),
            phone: value('phone'),
            company: value('company'),
            password: value('password'),
            acceptTerms: form.querySelector('[name="acceptTerms"]').checked,
            language: this.getConfig().get('language') === 'en_US' ? 'en_US' : 'pt_BR',
        };

        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        Espo.Ui.notifyWait();

        Espo.Ajax.postRequest('SelfService/signup', data)
            .then(result => {
                Espo.Ui.notify(false);
                this.selfServiceSave({signupId: result.signupId, token: result.token, email: data.email});
                this.selfServiceShowCode();
            })
            .catch(xhr => {
                button.disabled = false;
                this.selfServiceError(xhr, code => {
                    if (code === 'EMAIL_IN_USE') {
                        this.selfServiceShowLogin(data.email);
                    }
                });
            });
    }

    selfServiceShowCode() {
        const state = this.selfServiceState();

        this.selfServiceRender(
            `<form data-role="self-service-code" class="login-form" novalidate>
                ${this.selfServiceHeader('codeTitle', this.selfServiceEmphasis('codeSent', '{email}', state.email))}
                <div class="login-field">
                    <label for="self-service-code" class="login-label">${escape(this.selfServiceText('code'))}</label>
                    <input type="text" id="self-service-code" name="code" class="form-control login-code"
                        inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\\d{6}">
                </div>
                ${this.selfServiceActions('confirm')}
                <div class="login-links">
                    <button type="button" class="login-text-link" data-action="restart">${escape(this.selfServiceText('changeEmail'))}</button>
                    <button type="button" class="login-text-link" data-action="resend"></button>
                </div>
            </form>`
        );

        const form = this.selfServiceContainer.querySelector('form');
        const resend = form.querySelector('[data-action="resend"]');

        this.selfServiceStartResendCountdown(resend);

        resend.addEventListener('click', () => this.selfServiceResend(resend));
        form.querySelector('[data-action="restart"]').addEventListener('click', () => {
            sessionStorage.removeItem(STORAGE_KEY);
            this.selfServiceShowForm({email: state.email});
        });
        form.addEventListener('submit', event => {
            event.preventDefault();
            this.selfServiceVerify(form);
        });

        form.querySelector('[name="code"]').focus();
    }

    selfServiceStartResendCountdown(link) {
        let remaining = RESEND_SECONDS;

        clearInterval(this.selfServiceResendTimer);

        const tick = () => {
            if (remaining <= 0) {
                clearInterval(this.selfServiceResendTimer);
                link.textContent = this.selfServiceText('resend');
                link.disabled = false;

                return;
            }

            link.textContent = this.selfServiceText('resendIn').replace('{seconds}', String(remaining));
            link.disabled = true;
            remaining--;
        };

        tick();
        this.selfServiceResendTimer = setInterval(tick, 1000);
    }

    selfServiceResend(link) {
        if (link.disabled) {
            return;
        }

        const state = this.selfServiceState();

        Espo.Ajax.postRequest(`SelfService/signup/${encodeURIComponent(state.signupId)}/resend`, {token: state.token})
            .then(() => {
                Espo.Ui.success(this.selfServiceText('resent'));
                this.selfServiceStartResendCountdown(link);
            })
            .catch(xhr => this.selfServiceError(xhr));
    }

    selfServiceVerify(form) {
        const state = this.selfServiceState();
        const code = form.querySelector('[name="code"]').value.replace(/\D/g, '');
        const button = form.querySelector('[type="submit"]');

        button.disabled = true;
        Espo.Ui.notifyWait();

        Espo.Ajax.postRequest(`SelfService/signup/${encodeURIComponent(state.signupId)}/verify`, {
            token: state.token,
            code: code,
        })
            .then(result => {
                Espo.Ui.notify(false);
                this.selfServiceSave({...state, verified: true});
                this.selfServiceProgress(result, 0);
            })
            .catch(xhr => {
                button.disabled = false;
                this.selfServiceError(xhr, reason => {
                    // A new code doesn't give new guesses: start the signup over.
                    if (reason === 'TOO_MANY_ATTEMPTS') {
                        sessionStorage.removeItem(STORAGE_KEY);
                        this.selfServiceShowForm({email: state.email});
                    }
                });
            });
    }

    selfServiceProgress(result, polls) {
        if (result.status === 'ready' && result.ticket) {
            this.selfServiceLogin(result.ticket);

            return;
        }

        if (result.status === 'ready' || result.status === 'failed') {
            const failed = result.status === 'failed';

            this.selfServiceRender(
                `<div class="login-form">
                    ${this.selfServiceHeader(failed ? 'failedTitle' : 'readyTitle', escape(this.selfServiceText(failed ? 'failed' : 'readyLogin')))}
                    <button type="button" class="login-button login-button-primary"
                        data-action="showLogin">${escape(this.selfServiceText('goToLogin'))}</button>
                </div>`
            );
            this.selfServiceContainer.querySelector('[data-action="showLogin"]')
                .addEventListener('click', () => this.selfServiceShowLogin(this.selfServiceState().email));

            return;
        }

        if (polls === 0) {
            this.selfServiceRender(
                `<div class="login-form">
                    ${this.selfServiceHeader('creating')}
                    <p class="login-status" aria-live="polite">
                        <span class="login-spinner" aria-hidden="true"></span>
                        <span data-role="creating-hint">${escape(this.selfServiceText('creatingHint'))}</span>
                    </p>
                </div>`
            );
        }

        if (polls === SLOW_AFTER_POLLS) {
            const hint = this.selfServiceContainer.querySelector('[data-role="creating-hint"]');

            if (hint) {
                hint.textContent = this.selfServiceText('slow');
            }
        }

        const state = this.selfServiceState();

        this.selfServicePollTimer = setTimeout(() => {
            Espo.Ajax.postRequest(`SelfService/signup/${encodeURIComponent(state.signupId)}/status`, {token: state.token})
                .then(next => this.selfServiceProgress(next, polls + 1))
                .catch(xhr => {
                    if (xhr) {
                        xhr.errorIsHandled = true;
                    }

                    this.selfServiceProgress({status: 'provisioning'}, polls + 1);
                });
        }, POLL_MS);
    }

    selfServiceLogin(ticket) {
        sessionStorage.removeItem(STORAGE_KEY);
        localStorage.setItem('chat_redirect', '/app');

        const authString = btoa('**self-service:' + ticket);

        this.proceed({
            'Authorization': 'Basic ' + authString,
            'Espo-Authorization': authString,
        }, null);
    }

    /**
     * @param {boolean} [inline=true] Use the error line of the step on screen.
     */
    selfServiceError(xhr, onCode, inline = true) {
        if (!xhr) {
            return;
        }

        xhr.errorIsHandled = true;
        Espo.Ui.notify(false);

        const code = (xhr.responseJSON || {}).code || 'GENERIC';
        const translated = this.translate(code, 'selfServiceErrors', 'Global');
        const message = translated === code ? this.translate('GENERIC', 'selfServiceErrors', 'Global') : translated;

        if (code === 'SIGNUP_NOT_FOUND') {
            sessionStorage.removeItem(STORAGE_KEY);
            this.selfServiceShowForm();
        }

        if (onCode) {
            onCode(code);
        }

        // In the step on screen when it has an error line; otherwise (the
        // login page) as a notice.
        const line = !inline || this.selfServiceContainer.classList.contains('hidden') ?
            null : this.selfServiceContainer.querySelector('.login-error');

        if (!line) {
            Espo.Ui.error(message);

            return;
        }

        line.textContent = message;
        line.hidden = false;
    }

    selfServiceSave(state) {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    }

    selfServiceState() {
        try {
            return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '{}');
        } catch (e) {
            return {};
        }
    }

    /**
     * After a reload, continue where this tab left off.
     */
    selfServiceRestore() {
        const state = this.selfServiceState();

        if (!state.signupId || !state.token) {
            return false;
        }

        if (state.verified) {
            this.selfServiceProgress({status: 'provisioning'}, 0);
        } else {
            this.selfServiceShowCode();
        }

        return true;
    }
}
