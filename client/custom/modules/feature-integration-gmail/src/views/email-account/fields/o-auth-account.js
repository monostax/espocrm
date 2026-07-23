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
 * Shared OAuthAccount picker for EmailAccount (Gmail, Microsoft 365, …).
 * No provider filter — side-panel Connect buttons create the right account type.
 */
define("feature-integration-gmail:views/email-account/fields/o-auth-account", [
	"views/fields/link",
], (Dep) =>
	Dep.extend({
		createDisabled: false,
	}));
