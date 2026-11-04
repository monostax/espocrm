<div class="container content">
    <div class="container-centering">
    <div id="login" class="login-card">
        <div class="login-logo">
            <img src="{{logoSrc}}" class="logo" alt="">
        </div>
        <div class="panel-body{{#if anotherUser}} another-user{{/if}}">
            <div data-role="login-main">
                <form id="login-form" class="login-form">
                    <header class="login-header">
                        <h1 class="login-title">{{translate 'logInTitle' category='selfService'}}</h1>
                    </header>
                    {{#if hasSignIn}}
                    <div class="cell login-sign-in" data-name="sign-in">
                        <button
                            class="login-button"
                            id="sign-in"
                            type="button"
                        >{{signInText}}</button>
                        {{#if hasFallback}}
                        <a
                            role="button"
                            tabindex="0"
                            class="login-icon-button"
                            data-action="showFallback"
                            title="{{translate 'logInWithPassword' category='selfService'}}"
                            aria-label="{{translate 'logInWithPassword' category='selfService'}}"
                        ><span class="fas fa-chevron-down"></span></a>
                        {{/if}}
                    </div>
                    {{/if}}
                    <div class="form-group cell login-field" data-name="username">
                        <label for="field-userName" class="login-label">{{translate 'Username'}}</label>
                        <input
                            type="text"
                            name="username"
                            id="field-userName"
                            class="form-control"
                            autocapitalize="off"
                            spellcheck="false"
                            tabindex="1"
                            autocomplete="username"
                            maxlength="255"
                        >
                    </div>
                    <div class="form-group cell login-field" data-name="password">
                        <div class="login-label-row">
                            <label for="field-password" class="login-label">{{translate 'Password'}}</label>
                            {{#if showForgotPassword}}
                            <a
                                role="button"
                                class="login-text-link"
                                data-action="passwordChangeRequest"
                                tabindex="4"
                            >{{translate 'Forgot Password?' scope='User'}}</a>
                            {{/if}}
                        </div>
                        <div data-role="password-input-container" class="login-password">
                            <input
                                type="password"
                                name="password"
                                id="field-password"
                                class="form-control"
                                tabindex="2"
                                autocomplete="current-password"
                                maxlength="255"
                            >
                            <a
                                role="button"
                                data-action="toggleShowPassword"
                                class="login-password-toggle"
                                title="{{translate 'View'}}"
                                aria-label="{{translate 'View'}}"
                            ><span class="far fa-eye"></span></a>
                        </div>
                    </div>
                    {{#if anotherUser}}
                    <div class="form-group cell login-field">
                        <span class="login-label">{{translate 'Log in as'}}</span>
                        <div class="login-another-user">{{anotherUser}}</div>
                    </div>
                    {{/if}}
                    <div class="cell" data-name="submit">
                        <button
                            type="submit"
                            class="login-button login-button-primary"
                            id="btn-login"
                            tabindex="3"
                        >{{logInText}}</button>
                    </div>
                </form>
                <div data-role="self-service-social" class="hidden"></div>
                <p data-role="self-service-cta" class="login-switch hidden"></p>
            </div>
            <div data-role="self-service" class="hidden"></div>
        </div>
    </div>
    </div>
</div>
<footer>{{{footer}}}</footer>
