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
 * Gmail OAuthAccount picker for InboundEmail (group mailbox).
 * Restricts selection to OAuthAccounts whose provider discriminator is google-gmail.
 */
define("feature-integration-gmail:views/inbound-email/fields/o-auth-account", [
	"views/fields/link",
], (Dep) =>
	Dep.extend({
		selectPrimaryFilterName: "gmail",

		createDisabled: false,
	}));
