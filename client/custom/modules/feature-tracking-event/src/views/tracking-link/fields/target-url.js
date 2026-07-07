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
 * Target URL field for TrackingLink: url field that accepts human-pasted
 * IRIs (raw accented characters in query params — e.g. wa.me targets with
 * `text=Olá!...`) by percent-encoding non-ASCII characters on fetch(),
 * BEFORE the core url regExp validation runs. Without this the core
 * pattern (ASCII-only `uriOptionalProtocol`) rejects the paste with a
 * bare "{field} is invalid".
 *
 * Server mirror (API path): Classes/FieldSanitizers/AsciiUrl.php — keep
 * the encoding rule in sync (encode everything outside \x21-\x7E).
 */
import UrlFieldView from 'views/fields/url';

// noinspection JSUnusedGlobalSymbols
export default class extends UrlFieldView {

    fetch() {
        const data = super.fetch();

        const value = data[this.name];

        if (typeof value === 'string' && value !== '') {
            data[this.name] = value
                .trim()
                .replace(/[^\x21-\x7E]/g, character => encodeURIComponent(character));
        }

        return data;
    }
}
