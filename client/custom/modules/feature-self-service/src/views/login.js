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

const escape = value => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

export default class extends LoginView {

    afterRender() {
        super.afterRender();

        this.selfServicePollTimer = null;

        const panelBody = this.element.querySelector('.panel-body > div');

        if (!panelBody) {
            return;
        }

        this.selfServiceContainer = document.createElement('div');
        this.selfServiceContainer.dataset.role = 'self-service';
        this.selfServiceContainer.classList.add('hidden');
        panelBody.appendChild(this.selfServiceContainer);

        Espo.Ajax.getRequest('SelfService/config')
            .then(config => {
                if (config.state !== 'enabled' || this.isRemoved()) {
                    return;
                }

                this.selfServiceProviders = config.providers || [];
                this.selfServiceAddLink(panelBody);

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

    selfServiceAddLink(panelBody) {
        const cta = document.createElement('div');
        cta.className = 'margin-top-2x text-center';
        cta.dataset.role = 'self-service-cta';
        cta.innerHTML =
            `<span class="text-muted">${escape(this.selfServiceText('newHere'))}</span> ` +
            `<a role="button" tabindex="5" data-action="selfServiceSignup">${escape(this.selfServiceText('createAccount'))}</a>`;

        cta.innerHTML += this.selfServiceSocialButtons();

        panelBody.querySelector('#login-form')?.after(cta);

        cta.querySelector('[data-action="selfServiceSignup"]').addEventListener('click', () => this.selfServiceShowForm());
        this.selfServiceBindSocial(cta);
    }

    /**
     * "Continue with Google / Microsoft" for the providers the server has.
     */
    selfServiceSocialButtons() {
        if (!this.selfServiceProviders?.length) {
            return '';
        }

        const labels = {google: 'continueWithGoogle', microsoft: 'continueWithMicrosoft'};
        const icons = {google: 'fab fa-google', microsoft: 'fab fa-microsoft'};

        return `<div data-role="self-service-social" class="margin-top-2x">
            <div class="text-muted small margin-bottom-sm">${escape(this.selfServiceText('or'))}</div>
            ${this.selfServiceProviders.map(provider =>
                `<button type="button" class="btn btn-default btn-block margin-bottom-sm" data-provider="${escape(provider)}">
                    <span class="${icons[provider] || ''}"></span> ${escape(this.selfServiceText(labels[provider] || provider))}
                </button>`).join('')}
        </div>`;
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
                if (xhr) {
                    this.selfServiceError(xhr);
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
            `<form data-role="self-service-details" novalidate>
                <h4 class="margin-bottom">${escape(this.selfServiceText('detailsTitle'))}</h4>
                <p class="text-muted">${escape(this.selfServiceText('detailsSignedInAs').replace('{email}', result.email))}</p>
                <div class="form-group cell">
                    <label for="self-service-phone">${escape(this.selfServiceText('phone'))}</label>
                    <input type="tel" id="self-service-phone" name="phone" class="form-control"
                        autocomplete="tel" maxlength="20" placeholder="(11) 91234-5678">
                </div>
                <div class="form-group cell">
                    <label for="self-service-company">${escape(this.selfServiceText('company'))}</label>
                    <input type="text" id="self-service-company" name="company" class="form-control"
                        autocomplete="organization" maxlength="100">
                </div>
                ${this.selfServiceTermsCheckbox(false)}
                <div class="margin-top-2x cell">
                    <button type="submit" class="btn btn-primary btn-s-wide">${escape(this.selfServiceText('submit'))}</button>
                    <a role="button" class="btn btn-link btn-text btn-sm pull-right margin-top-sm"
                        data-action="showLogin">${escape(this.selfServiceText('back'))}</a>
                </div>
            </form>`
        );

        const form = this.selfServiceContainer.querySelector('form');

        form.querySelector('[data-action="showLogin"]').addEventListener('click', () => this.selfServiceShowLogin());
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
        return `<div class="checkbox">
            <label>
                <input type="checkbox" name="acceptTerms" ${checked ? 'checked' : ''}>
                ${escape(this.selfServiceText('acceptTermsPrefix'))}
                <a href="${TERMS_URL}" target="_blank" rel="noopener">${escape(this.selfServiceText('terms'))}</a>
                ${escape(this.selfServiceText('and'))}
                <a href="${PRIVACY_URL}" target="_blank" rel="noopener">${escape(this.selfServiceText('privacy'))}</a>
            </label>
        </div>`;
    }

    selfServiceToggle(signupVisible) {
        this.element.querySelector('#login-form')?.classList.toggle('hidden', signupVisible);
        this.element.querySelector('[data-role="self-service-cta"]')?.classList.toggle('hidden', signupVisible);
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
    }

    selfServiceShowForm(values) {
        values = values || {};

        const field = (name, type, autocomplete, extra) =>
            `<div class="form-group cell">
                <label for="self-service-${name}">${escape(this.selfServiceText(name))}</label>
                <input type="${type}" id="self-service-${name}" name="${name}" class="form-control"
                    autocomplete="${autocomplete}" value="${escape(values[name])}" ${extra || ''}>
            </div>`;

        this.selfServiceRender(
            `<form data-role="self-service-form" novalidate>
                <h4 class="margin-bottom">${escape(this.selfServiceText('title'))}</h4>
                <p class="text-muted">${escape(this.selfServiceText('subtitle'))}</p>
                ${field('name', 'text', 'name', 'maxlength="100"')}
                ${field('email', 'email', 'email', 'maxlength="254" autocapitalize="off" spellcheck="false"')}
                ${field('phone', 'tel', 'tel', 'maxlength="20" placeholder="(11) 91234-5678"')}
                ${field('company', 'text', 'organization', 'maxlength="100"')}
                ${field('password', 'password', 'new-password', 'maxlength="128" minlength="8"')}
                ${this.selfServiceTermsCheckbox(values.acceptTerms)}
                <div class="margin-top-2x cell">
                    <button type="submit" class="btn btn-primary btn-s-wide">${escape(this.selfServiceText('submit'))}</button>
                    <a role="button" class="btn btn-link btn-text btn-sm pull-right margin-top-sm"
                        data-action="showLogin">${escape(this.selfServiceText('haveAccount'))}</a>
                </div>
                ${this.selfServiceSocialButtons()}
            </form>`
        );

        const form = this.selfServiceContainer.querySelector('form');

        this.selfServiceBindSocial(form);

        form.querySelector('[data-action="showLogin"]').addEventListener('click', () => this.selfServiceShowLogin());
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
            `<form data-role="self-service-code" novalidate>
                <h4 class="margin-bottom">${escape(this.selfServiceText('codeTitle'))}</h4>
                <p class="text-muted">${escape(this.selfServiceText('codeSent').replace('{email}', state.email))}</p>
                <div class="form-group cell">
                    <label for="self-service-code">${escape(this.selfServiceText('code'))}</label>
                    <input type="text" id="self-service-code" name="code" class="form-control"
                        inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\\d{6}">
                </div>
                <div class="margin-top-2x cell">
                    <button type="submit" class="btn btn-primary btn-s-wide">${escape(this.selfServiceText('confirm'))}</button>
                    <a role="button" class="btn btn-link btn-text btn-sm pull-right margin-top-sm"
                        data-action="resend"></a>
                </div>
                <div class="margin-top">
                    <a role="button" class="btn btn-link btn-text btn-sm" data-action="restart">${escape(this.selfServiceText('changeEmail'))}</a>
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
                link.classList.remove('disabled');

                return;
            }

            link.textContent = this.selfServiceText('resendIn').replace('{seconds}', String(remaining));
            link.classList.add('disabled');
            remaining--;
        };

        tick();
        this.selfServiceResendTimer = setInterval(tick, 1000);
    }

    selfServiceResend(link) {
        if (link.classList.contains('disabled')) {
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
                    if (reason === 'TOO_MANY_ATTEMPTS' || reason === 'CODE_EXPIRED') {
                        this.selfServiceStartResendCountdown(form.querySelector('[data-action="resend"]'));
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
            this.selfServiceRender(
                `<h4 class="margin-bottom">${escape(this.selfServiceText(result.status === 'failed' ? 'failedTitle' : 'readyTitle'))}</h4>
                <p class="text-muted">${escape(this.selfServiceText(result.status === 'failed' ? 'failed' : 'readyLogin'))}</p>
                <button type="button" class="btn btn-primary btn-s-wide" data-action="showLogin">${escape(this.selfServiceText('goToLogin'))}</button>`
            );
            this.selfServiceContainer.querySelector('[data-action="showLogin"]')
                .addEventListener('click', () => this.selfServiceShowLogin(this.selfServiceState().email));

            return;
        }

        if (polls === 0) {
            this.selfServiceRender(
                `<h4 class="margin-bottom">${escape(this.selfServiceText('creating'))}</h4>
                <p class="text-muted" data-role="creating-hint">${escape(this.selfServiceText('creatingHint'))}</p>
                <div class="text-center margin-top-2x"><span class="fas fa-spinner fa-spin fa-2x text-muted"></span></div>`
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

    selfServiceError(xhr, onCode) {
        if (!xhr) {
            return;
        }

        xhr.errorIsHandled = true;
        Espo.Ui.notify(false);

        const code = (xhr.responseJSON || {}).code || 'GENERIC';
        const message = this.translate(code, 'selfServiceErrors', 'Global');

        Espo.Ui.error(message === code ? this.translate('GENERIC', 'selfServiceErrors', 'Global') : message);

        if (code === 'SIGNUP_NOT_FOUND') {
            sessionStorage.removeItem(STORAGE_KEY);
            this.selfServiceShowForm();
        }

        if (onCode) {
            onCode(code);
        }
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
