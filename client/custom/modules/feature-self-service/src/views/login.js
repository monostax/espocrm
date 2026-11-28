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
 * Signup: form → emailed code → phone check (a 6-digit code we send to the
 * number in the form, by WhatsApp or SMS; status `phone`) → workspace creation → login with a one-time
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
import {byIso, countries, countryName, defaultCountry, flagUrl, joinPhone, splitPhone}
    from 'feature-self-service:helpers/phone-countries';

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

// Where a code went, over the step's title: the email code and the phone code must never look alike.
const STEP_ICONS = {
    email: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" ' +
        'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.5 7 7.4 5.4a1.9 1.9 0 0 0 2.2 0L20.5 7"/>' +
        '</svg>',
    whatsapp: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.26-.46-2.39-1.48-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.18.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.07c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.7.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.18-1.42-.08-.12-.27-.2-.57-.34m-5.42 7.4h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88a9.82 9.82 0 0 1 9.88 9.89c0 5.45-4.44 9.88-9.88 9.88m8.41-18.3A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.9a11.82 11.82 0 0 0-3.48-8.41z"/></svg>',
    sms: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" ' +
        'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M20 15a2 2 0 0 1-2 2H8l-4 3.5V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2z"/><path d="M8.5 10.5h.01M12 10.5h.01M15.5 10.5h.01" stroke-width="2.25"/>' +
        '</svg>',
};

// How long a code that checks out shows its check before the next step.
const SUCCESS_MS = 900;
const SUCCESS_REDUCED_MS = 400;

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
                this.selfServicePhoneCheck = !!config.phoneCheck;
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
                ${this.selfServicePhoneField('self-service-phone', '', this.selfServicePhoneHint())}
                ${this.selfServiceField('company', 'text', 'organization', 'maxlength="100"', this.selfServicePreview().company)}
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
                phone: this.selfServicePhoneValue(form),
                company: form.querySelector('[name="company"]').value,
                acceptTerms: form.querySelector('[name="acceptTerms"]').checked,
                language: this.getConfig().get('language') === 'en_US' ? 'en_US' : 'pt_BR',
                ...this.selfServiceOrigin(),
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

    /** Under the phone field, when the signup checks the number: a code will go to it. */
    selfServicePhoneHint() {
        return this.selfServicePhoneCheck ? 'phoneHint' : null;
    }

    /**
     * A phone field: the country (flag and dial code, starting on the browser's
     * time zone) before the number, as in Chatwoot. Read it with
     * selfServicePhoneValue; selfServiceRender wires the country menu.
     */
    selfServicePhoneField(id, value, hintKey) {
        const {country, national} = splitPhone(value, this.selfServicePhoneCountry || defaultCountry());
        const search = escape(this.selfServiceText('phoneCountrySearch'));
        const hint = hintKey ?
            `<p class="login-hint" id="${id}-hint">${escape(this.selfServiceText(hintKey))}</p>` : '';

        return `<div class="login-field" data-role="phone-field" data-iso="${country.iso}">
            <label for="${id}" class="login-label">${escape(this.selfServiceText('phone'))}</label>
            <div class="login-phone">
                <button type="button" class="login-phone-country" data-action="phoneCountry" aria-haspopup="listbox"
                    aria-expanded="false" aria-label="${escape(this.selfServiceText('phoneCountry'))}">
                    <img class="login-flag" src="${flagUrl(country.iso)}" alt="" width="20" height="15">
                    <span class="login-phone-dial">+${country.dial}</span>
                </button>
                <input type="tel" id="${id}" name="phone" class="form-control" autocomplete="tel-national"
                    inputmode="tel" maxlength="20" value="${escape(national)}"
                    placeholder="${country.iso === 'BR' ? '(11) 91234-5678' : ''}"
                    ${hintKey ? `aria-describedby="${id}-hint"` : ''}>
                <div class="login-phone-menu" hidden>
                    <input type="search" class="form-control login-phone-search" placeholder="${search}" aria-label="${search}">
                    <ul class="login-phone-list" role="listbox" aria-label="${escape(this.selfServiceText('phoneCountry'))}"></ul>
                </div>
            </div>
            ${hint}
        </div>`;
    }

    /** The number with its country's dial code ("+5511912345678"), for the CRM. */
    selfServicePhoneValue(scope) {
        const field = scope.querySelector('[data-role="phone-field"]');

        return joinPhone(byIso(field.dataset.iso) || defaultCountry(), field.querySelector('[name="phone"]').value);
    }

    /** The country menu of a phone field: names in the page's language, searchable, flags loaded as they show. */
    selfServiceBindPhone(field) {
        const button = field.querySelector('[data-action="phoneCountry"]');
        const menu = field.querySelector('.login-phone-menu');
        const search = menu.querySelector('.login-phone-search');
        const list = menu.querySelector('.login-phone-list');
        const number = field.querySelector('[name="phone"]');
        const language = this.getConfig().get('language') === 'en_US' ? 'en' : 'pt-BR';

        const close = () => {
            menu.hidden = true;
            button.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', outside, true);
        };
        const outside = event => {
            if (!field.contains(event.target)) {
                close();
            }
        };
        const pick = iso => {
            const country = byIso(iso);

            if (!country) {
                return;
            }

            this.selfServicePhoneCountry = country;
            field.dataset.iso = country.iso;
            button.querySelector('.login-flag').src = flagUrl(country.iso);
            button.querySelector('.login-phone-dial').textContent = '+' + country.dial;
            number.placeholder = country.iso === 'BR' ? '(11) 91234-5678' : '';
            close();
            number.focus();
        };
        const filter = () => {
            const query = search.value.trim().toLowerCase().replace(/^\+/, '');

            list.querySelectorAll('li').forEach(item => {
                item.hidden = !!query && !item.dataset.search.includes(query);
            });
        };

        button.addEventListener('click', () => {
            if (!menu.hidden) {
                close();

                return;
            }

            if (!list.children.length) {
                list.innerHTML = countries
                    .map(country => ({...country, name: countryName(country.iso, language)}))
                    .sort((a, b) => a.name.localeCompare(b.name, language))
                    .map(country => `<li role="option" data-iso="${country.iso}"
                        data-search="${escape(`${country.name} ${country.dial} ${country.iso}`.toLowerCase())}">
                        <img class="login-flag" src="${flagUrl(country.iso)}" alt="" width="20" height="15" loading="lazy">
                        <span>${escape(country.name)}</span><span class="login-phone-option-dial">+${country.dial}</span>
                    </li>`)
                    .join('');
            }

            list.querySelectorAll('li').forEach(item => {
                item.setAttribute('aria-selected', String(item.dataset.iso === field.dataset.iso));
            });
            search.value = '';
            filter();
            menu.hidden = false;
            button.setAttribute('aria-expanded', 'true');
            document.addEventListener('click', outside, true);
            search.focus();
            list.querySelector('[aria-selected="true"]')?.scrollIntoView({block: 'nearest'});
        });

        search.addEventListener('input', filter);
        search.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close();
                button.focus();
            }

            if (event.key === 'Enter') {
                event.preventDefault();
                pick(list.querySelector('li:not([hidden])')?.dataset.iso);
            }
        });
        list.addEventListener('click', event => pick(event.target.closest('li')?.dataset.iso));
    }

    /**
     * A step's title and an optional description, given as HTML. A code step names where the code went
     * (`icon`: email, whatsapp or sms) with a mark over the title, which turns into a check once the code
     * checks out (selfServiceSucceed).
     */
    selfServiceHeader(titleKey, descriptionHtml, icon) {
        const badge = icon ? `<div class="login-badge login-badge-${icon}" data-role="step-badge" aria-hidden="true">
                <span class="login-badge-icon">${STEP_ICONS[icon]}</span>
                <svg class="login-badge-check" viewBox="0 0 24 24"><path pathLength="1" d="m6.5 12.5 3.7 3.7 7.3-8.2"/></svg>
            </div>` : '';

        return `<header class="login-header">
            ${badge}
            <h1 class="login-title">${escape(this.selfServiceText(titleKey))}</h1>
            ${descriptionHtml ? `<p class="login-description">${descriptionHtml}</p>` : ''}
        </header>`;
    }

    /**
     * A code that checked out: the step's mark turns into a check and the code field goes green, and the
     * step holds still (inert) until the next one replaces it.
     */
    selfServiceSucceed(form) {
        const badge = form.querySelector('[data-role="step-badge"]');

        if (!badge) {
            return Promise.resolve();
        }

        const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

        form.inert = true;
        form.classList.add('is-success');
        badge.classList.add('is-success');

        return new Promise(resolve => setTimeout(resolve, reduced ? SUCCESS_REDUCED_MS : SUCCESS_MS));
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
        this.selfServiceContainer.querySelectorAll('[data-role="phone-field"]')
            .forEach(field => this.selfServiceBindPhone(field));

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

    /**
     * What the monostax.ai site preview (/previa) put in the signup link: the preview id, which the new workspace
     * starts from (its funnel and AI agent setup), and the company name to prefill. Sign-in with Google or
     * Microsoft runs in a popup, so this tab's address, and these, stay put.
     */
    selfServicePreview() {
        const params = new URLSearchParams(window.location.search);
        const preview = params.get('preview') || '';

        return {
            preview: /^[a-z0-9]{20}$/.test(preview) ? preview : '',
            company: (params.get('company') || '').replace(/\s+/g, ' ').trim().slice(0, 100),
        };
    }

    /**
     * Everything the site's signup link carries that the signup keeps: the preview, the funnel the visitor picked
     * (`job`: whatsapp, agente or funil), the connect screen to open first (`connect`: api or qr) and the visit's
     * UTMs and ad click ids. The server checks every value; unknown ones are dropped.
     */
    selfServiceOrigin() {
        const params = new URLSearchParams(window.location.search);
        const keys = [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id',
            'gclid', 'gbraid', 'wbraid', 'fbclid', 'keyword', 'search_term', 'matchtype', 'network', 'device',
        ];
        const attribution = {};

        keys.forEach(key => {
            const value = (params.get(key) || '').trim();

            if (value) {
                attribution[key] = value.slice(0, 200);
            }
        });

        return {
            preview: this.selfServicePreview().preview,
            job: params.get('job') || '',
            connect: params.get('connect') || '',
            attribution,
        };
    }

    selfServiceShowForm(values) {
        values = values || {};

        if (values.company === undefined && this.selfServicePreview().company) {
            values = {...values, company: this.selfServicePreview().company};
        }

        const field = (name, type, autocomplete, attributes, hintKey) =>
            this.selfServiceField(name, type, autocomplete, attributes, values[name], hintKey);

        this.selfServiceRender(
            `<form data-role="self-service-form" class="login-form" novalidate>
                ${this.selfServiceHeader('title', escape(this.selfServiceText('subtitle')))}
                ${this.selfServiceSocialButtons('before')}
                ${field('name', 'text', 'name', 'maxlength="100"')}
                ${field('email', 'email', 'email', 'maxlength="254" autocapitalize="off" spellcheck="false"')}
                ${this.selfServicePhoneField('self-service-phone', values.phone, this.selfServicePhoneHint())}
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
            phone: this.selfServicePhoneValue(form),
            company: value('company'),
            password: value('password'),
            acceptTerms: form.querySelector('[name="acceptTerms"]').checked,
            language: this.getConfig().get('language') === 'en_US' ? 'en_US' : 'pt_BR',
            ...this.selfServiceOrigin(),
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
                ${this.selfServiceHeader('codeTitle', this.selfServiceEmphasis('codeSent', '{email}', state.email), 'email')}
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
                this.selfServiceSucceed(form).then(() => this.selfServiceProgress(result, 0));
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

        if (result.status === 'phone') {
            // Nothing to wait for: the step goes on when the code is typed in.
            this.selfServiceShowPhone(result.phone || {});

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

                    // A failed poll keeps the step on screen.
                    this.selfServiceProgress({status: 'provisioning'}, polls + 1);
                });
        }, POLL_MS);
    }

    /**
     * The phone check: the 6-digit code we sent to the number in the form, by
     * WhatsApp (or by SMS on request). "Trocar número" corrects the number and
     * sends a new code. Nothing is polled: the code typed in moves on.
     */
    selfServiceShowPhone(phone) {
        const sent = phone.pending && (phone.whatsApp || phone.sms);
        const intro = sent ?
            this.selfServiceEmphasis(phone.sms ? 'phoneSentSms' : 'phoneSent', '{number}', phone.display || '') :
            escape(this.selfServiceText('phoneNotSent'));

        this.selfServiceRender(
            `<form data-role="self-service-phone" class="login-form" novalidate>
                ${this.selfServiceHeader('phoneTitle', intro, sent && phone.sms ? 'sms' : 'whatsapp')}
                <div class="login-field">
                    <label for="self-service-phone-code" class="login-label">${escape(this.selfServiceText('code'))}</label>
                    <input type="text" id="self-service-phone-code" name="code" class="form-control login-code"
                        inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\\d{6}">
                </div>
                ${this.selfServiceActions('confirm')}
                <div class="login-links">
                    <button type="button" class="login-text-link" data-action="phoneChange">${escape(this.selfServiceText('phoneChange'))}</button>
                    <button type="button" class="login-text-link" data-action="phoneResend"></button>
                    <button type="button" class="login-text-link" data-action="phoneSms" hidden></button>
                </div>
                <div class="login-phone-change" data-role="phone-change" hidden>
                    ${this.selfServicePhoneField('self-service-phone-new', phone.phone || '', null)}
                    <button type="button" class="login-button" data-action="phoneSend">${escape(this.selfServiceText('phoneSend'))}</button>
                </div>
            </form>`
        );

        const form = this.selfServiceContainer.querySelector('form');
        const resend = form.querySelector('[data-action="phoneResend"]');
        const sms = form.querySelector('[data-action="phoneSms"]');
        const change = form.querySelector('[data-role="phone-change"]');
        const resendAt = Date.parse(phone.resendAt || '') || 0;
        const smsAt = Date.parse(phone.smsAt || '') || 0;

        // The links say when they work; a code that never went out can be asked for at once.
        const tick = () => {
            const now = Date.now();
            const resendLeft = sent ? Math.ceil((resendAt - now) / 1000) : 0;
            const smsLeft = Math.ceil((smsAt - now) / 1000);

            resend.disabled = resendLeft > 0;
            resend.textContent = resendLeft > 0 ?
                this.selfServiceText('resendIn').replace('{seconds}', String(resendLeft)) :
                this.selfServiceText('resend');

            sms.hidden = !phone.pending || !phone.smsAvailable;
            sms.disabled = smsLeft > 0;
            sms.textContent = smsLeft > 0 ?
                this.selfServiceText('phoneSmsIn').replace('{seconds}', String(smsLeft)) :
                this.selfServiceText('phoneSms');
        };

        clearInterval(this.selfServiceResendTimer);
        tick();
        this.selfServiceResendTimer = setInterval(tick, 1000);

        const ask = (button, body, notice) => {
            const state = this.selfServiceState();

            button.disabled = true;

            Espo.Ajax.postRequest(`SelfService/signup/${encodeURIComponent(state.signupId)}/phone`, {token: state.token, ...body})
                .then(result => {
                    if (result.status !== 'phone') {
                        this.selfServiceProgress(result, 0);

                        return;
                    }

                    this.selfServiceShowPhone(result.phone || {});

                    if (notice) {
                        Espo.Ui.success(this.selfServiceText(notice));
                    }
                })
                .catch(xhr => {
                    button.disabled = false;
                    this.selfServiceError(xhr);
                });
        };

        resend.addEventListener('click', () => !resend.disabled && ask(resend, {}, 'resent'));
        sms.addEventListener('click', () => !sms.disabled && ask(sms, {channel: 'sms'}, 'phoneSmsSent'));
        form.querySelector('[data-action="phoneChange"]').addEventListener('click', () => {
            change.hidden = false;
            change.querySelector('[name="phone"]').focus();
        });

        const sendTo = form.querySelector('[data-action="phoneSend"]');

        sendTo.addEventListener('click', () => ask(sendTo, {phone: this.selfServicePhoneValue(change)}, null));

        form.addEventListener('submit', event => {
            event.preventDefault();
            this.selfServiceVerifyPhone(form, change);
        });

        if (phone.taken) {
            const line = form.querySelector('.login-error');

            line.textContent = this.selfServiceText('phoneTaken');
            line.hidden = false;
            change.hidden = false;
        }

        form.querySelector('[name="code"]').focus();
    }

    selfServiceVerifyPhone(form, change) {
        const state = this.selfServiceState();
        const code = form.querySelector('[name="code"]').value.replace(/\D/g, '');
        const button = form.querySelector('[type="submit"]');

        button.disabled = true;
        Espo.Ui.notifyWait();

        Espo.Ajax.postRequest(`SelfService/signup/${encodeURIComponent(state.signupId)}/phone/verify`, {
            token: state.token,
            code: code,
        })
            .then(result => {
                Espo.Ui.notify(false);
                clearInterval(this.selfServiceResendTimer);
                this.selfServiceSucceed(form).then(() => this.selfServiceProgress(result, 0));
            })
            .catch(xhr => {
                button.disabled = false;
                this.selfServiceError(xhr, reason => {
                    // The number already has a workspace: another one can be tried right here.
                    if (reason === 'PHONE_TAKEN') {
                        change.hidden = false;
                    }
                });
            });
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

        const code = this.selfServiceErrorCode(xhr);
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

    /**
     * The SelfService API's `{"code": …}` body. Espo.Ajax rejects with a bare XMLHttpRequest
     * (no jQuery `responseJSON`), so it is read from the response text.
     */
    selfServiceErrorCode(xhr) {
        try {
            return (xhr.responseJSON || JSON.parse(xhr.responseText || '{}')).code || 'GENERIC';
        } catch (e) {
            return 'GENERIC';
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
