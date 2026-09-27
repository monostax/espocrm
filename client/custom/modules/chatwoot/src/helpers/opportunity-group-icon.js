import RecordIcon from "helpers/record-icon";

/*
 * Lucide paths match Chatwoot's sidebar (@iconify-json/lucide).
 * ISC License — Copyright (c) 2026 Lucide Icons and Contributors.
 * Permission to use, copy, modify, and/or distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 *
 * Feather-derived paths: MIT License — Copyright (c) 2013-present Cole Bemis.
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */
const paths = {
    circle: '<circle cx="12" cy="12" r="10"/>',
    "circle-check": '<circle cx="12" cy="12" r="10"/><path d="m16 9l-5.5 5.5L8 12"/>',
    "circle-x": '<circle cx="12" cy="12" r="10"/><path d="m15 9l-6 6m0-6l6 6"/>',
    "calendar-x-2": '<path d="M16 2v3m1 11l5 5m-5 0l5-5m-1-4V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8M3 9h18M8 2v3"/>',
    "calendar-chevrons-right": '<path d="m13 21l3-3l-3-3m3-13v3m3 16l3-3l-3-3"/><path d="M21 11.5V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4M3 9h18M8 2v3"/>',
    "calendar-clock": '<path d="M16 14v2.2l1.6 1M16 2v3m5 2.338V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h2.338M3 9h5.859M8 2v3"/><circle cx="16" cy="16" r="6"/>',
    "calendar-check": '<path d="M8 2v3m8-3v3"/><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18M9 15l2 2l4-4"/>',
    mail: '<path d="m22 7l-8.991 5.727a2 2 0 0 1-2.009 0L2 7"/><rect width="20" height="16" x="2" y="4" rx="2"/>',
    "mail-open": '<path d="M21.2 8.4c.5.38.8.97.8 1.6v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V10a2 2 0 0 1 .8-1.6l8-6a2 2 0 0 1 2.4 0z"/><path d="m22 10l-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 10"/>',
    inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11"/>',
    filter: '<path d="M22 3H2l8 9.46V19l4 2v-8.54z"/>',
    "user-round": '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
};

const icons = {
    all: "inbox",
    read: "mail-open",
    unread: "mail",
    "status:Open": "circle",
    "status:Won": "circle-check",
    "status:Lost": "circle-x",
    "activity:overdue": "calendar-x-2",
    "activity:noNextAction": "calendar-chevrons-right",
    "activity:closed": "calendar-check",
};

const svg = name => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" ` +
    `stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">` +
    paths[name] + '</svg>';

export default class OpportunityGroupIcon {
    constructor(view) {
        this.view = view;
    }

    html({key, stageStyle, icon}) {
        const [type, id] = key.split(":");
        if (type === "stage" || type === "stageName") {
            const name = ["success", "danger", "warning", "primary", "info"].includes(stageStyle) ? stageStyle : "default";
            return `<span class="table-group-stage-indicator" data-style="${name}"></span>`;
        }
        if (type === "funnel") {
            return RecordIcon.html(icon, this.view.getMetadata()) || svg("filter");
        }
        if (type === "assignee") {
            return (id && this.view.getHelper().getAvatarHtml(id, "small", 16)) || svg("user-round");
        }
        return svg(icons[key] || (type === "activity" ? "calendar-clock" : "circle"));
    }
}
