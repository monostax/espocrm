/**
 * Client-side compile/decompile: unified transition rules ↔ engine fields.
 * User edits one AND/OR tree; wakes/eventCodes/waitPeriod are derived.
 */
define("feature-journey:helpers/transition-rules", [], function () {
    const WAKE_SIGNAL = "signal";
    const WAKE_TIMER = "timer";
    const WAKE_ENTITY = "entityChange";
    const WAKE_MANUAL = "manual";

    /**
     * @param {*} tree
     * @return {boolean}
     */
    function isEmptyTree(tree) {
        if (!tree || typeof tree !== "object") {
            return true;
        }

        if (Array.isArray(tree.and)) {
            return tree.and.length === 0;
        }

        if (Array.isArray(tree.or)) {
            return tree.or.length === 0;
        }

        if (tree.not) {
            return false;
        }

        if (tree.type) {
            return false;
        }

        return Object.keys(tree).length === 0;
    }

    /**
     * @param {*} tree
     * @param {function(object):void} visit
     */
    function walkLeaves(tree, visit) {
        if (!tree || typeof tree !== "object") {
            return;
        }

        if (Array.isArray(tree.and)) {
            tree.and.forEach((c) => {
                walkLeaves(c, visit);
            });

            return;
        }

        if (Array.isArray(tree.or)) {
            tree.or.forEach((c) => {
                walkLeaves(c, visit);
            });

            return;
        }

        if (tree.not && typeof tree.not === "object") {
            walkLeaves(tree.not, visit);

            return;
        }

        if (tree.type) {
            visit(tree);
        }
    }

    /**
     * @param {*} tree
     * @param {{allowManual?: boolean, allowEntityChange?: boolean, existingWait?: string|null}} opts
     */
    function compile(tree, opts) {
        opts = opts || {};
        const wakes = [];
        const codes = [];
        const periods = [];

        walkLeaves(tree, (node) => {
            const t = node.type;

            if (t === "eventHistory" || t === "currentSignal" || t === "anySignal") {
                addWake(wakes, WAKE_SIGNAL);

                if (typeof node.code === "string" && node.code) {
                    addCode(codes, node.code);
                }

                if (Array.isArray(node.codes)) {
                    node.codes.forEach((c) => {
                        addCode(codes, c);
                    });
                }

                return;
            }

            if (t === "payloadPath") {
                addWake(wakes, WAKE_SIGNAL);

                return;
            }

            if (t === "elapsedInStage") {
                addWake(wakes, WAKE_TIMER);
                const p = node.period || node.waitPeriod;

                if (typeof p === "string" && p.trim()) {
                    periods.push(p.trim());
                }

                return;
            }

            if (t === "entityFilter" || t === "onRecordChange") {
                addWake(wakes, WAKE_ENTITY);

                return;
            }

            if (t === "manual") {
                addWake(wakes, WAKE_MANUAL);
            }
        });

        if (opts.allowManual) {
            addWake(wakes, WAKE_MANUAL);
        }

        if (opts.allowEntityChange) {
            addWake(wakes, WAKE_ENTITY);
        }

        let waitPeriod = null;

        if (periods.length) {
            waitPeriod = periods[0];

            for (let i = 1; i < periods.length; i++) {
                waitPeriod = pickShorterPeriod(waitPeriod, periods[i]);
            }
        } else if (wakes.indexOf(WAKE_TIMER) !== -1 && opts.existingWait) {
            waitPeriod = String(opts.existingWait);
        }

        const primaryOrder = [WAKE_SIGNAL, WAKE_TIMER, WAKE_ENTITY, WAKE_MANUAL];
        let triggerType = WAKE_SIGNAL;

        for (let i = 0; i < primaryOrder.length; i++) {
            if (wakes.indexOf(primaryOrder[i]) !== -1) {
                triggerType = primaryOrder[i];
                break;
            }
        }

        return {
            wakeSources: wakes,
            eventCodes: codes,
            waitPeriod: waitPeriod,
            triggerType: triggerType,
            allowManual: wakes.indexOf(WAKE_MANUAL) !== -1,
            allowEntityChange: wakes.indexOf(WAKE_ENTITY) !== -1,
        };
    }

    /**
     * @param {object} modelLike
     */
    function decompile(modelLike) {
        let tree = normalizeTree(modelLike.conditionsGroup);
        const wakes = resolveWakes(modelLike);
        const allowManual = wakes.indexOf(WAKE_MANUAL) !== -1;
        const allowEntityChange = wakes.indexOf(WAKE_ENTITY) !== -1;

        if (!isEmptyTree(tree)) {
            return {
                tree: tree,
                allowManual: allowManual,
                allowEntityChange: allowEntityChange,
                synthesized: false,
            };
        }

        const leaves = [];
        const codes = normalizeCodes(modelLike.eventCodes);
        const wait = modelLike.waitPeriod ? String(modelLike.waitPeriod) : "";

        if (wakes.indexOf(WAKE_SIGNAL) !== -1 && codes.length) {
            if (codes.length === 1) {
                leaves.push({
                    type: "currentSignal",
                    code: codes[0],
                });
            } else {
                leaves.push({
                    type: "anySignal",
                    codes: codes.slice(),
                });
            }
        }

        if (wakes.indexOf(WAKE_TIMER) !== -1 && wait) {
            leaves.push({
                type: "elapsedInStage",
                period: wait,
            });
        }

        if (!leaves.length) {
            tree = {and: []};
        } else if (leaves.length === 1) {
            tree = {and: leaves};
        } else {
            tree = {or: leaves};
        }

        return {
            tree: tree,
            allowManual: allowManual,
            allowEntityChange: allowEntityChange,
            synthesized: leaves.length > 0,
        };
    }

    function normalizeTree(value) {
        if (!value) {
            return {and: []};
        }

        let v = value;

        if (typeof v === "string") {
            try {
                v = JSON.parse(v);
            } catch (e) {
                return {and: []};
            }
        }

        if (Array.isArray(v)) {
            return {and: v};
        }

        if (typeof v === "object") {
            if (v.and || v.or || v.not || v.type) {
                return v;
            }
        }

        return {and: []};
    }

    function resolveWakes(modelLike) {
        const raw = modelLike.wakeSources;
        const out = [];

        if (Array.isArray(raw)) {
            raw.forEach((w) => {
                if (typeof w === "string" && w && out.indexOf(w) === -1) {
                    out.push(w);
                }
            });
        }

        if (out.length) {
            return out;
        }

        const t = modelLike.triggerType;

        if (typeof t === "string" && t && t !== "formula") {
            return [t];
        }

        return [];
    }

    function normalizeCodes(raw) {
        if (Array.isArray(raw)) {
            return raw.filter((c) => typeof c === "string" && c !== "");
        }

        if (typeof raw === "string" && raw !== "") {
            return [raw];
        }

        return [];
    }

    function addWake(wakes, w) {
        if (wakes.indexOf(w) === -1) {
            wakes.push(w);
        }
    }

    function addCode(codes, c) {
        if (typeof c === "string" && c && codes.indexOf(c) === -1) {
            codes.push(c);
        }
    }

    function pickShorterPeriod(a, b) {
        const sa = scorePeriod(a);
        const sb = scorePeriod(b);

        if (sa === null && sb === null) {
            return a;
        }

        if (sa === null) {
            return b;
        }

        if (sb === null) {
            return a;
        }

        return sa <= sb ? a : b;
    }

    /**
     * Duration in seconds, or null when unparseable.
     *
     * Accepts English and pt-BR unit words so a rule typed as "3 dias" scores the same
     * as "3 days". Mirrors PeriodParser.php — keep the alias table in sync with:
     *   custom/Espo/Modules/FeatureJourney/Services/PeriodParser.php
     *   client/custom/modules/feature-journey/src/views/fields/period.js
     */
    function scorePeriod(p) {
        if (!p || typeof p !== "string") {
            return null;
        }

        const m = p.trim().match(/^(\d+)\s*([A-Za-zÀ-ÿ]+)$/);

        if (!m) {
            return null;
        }

        const u = m[2]
            .replace(/[áàãâÁÀÃÂ]/g, "a")
            .replace(/[éêÉÊ]/g, "e")
            .replace(/[íÍ]/g, "i")
            .replace(/[óôõÓÔÕ]/g, "o")
            .replace(/[úÚ]/g, "u")
            .replace(/[çÇ]/g, "c")
            .toLowerCase();

        const mult = {
            // English
            second: 1, seconds: 1,
            minute: 60, minutes: 60,
            hour: 3600, hours: 3600,
            day: 86400, days: 86400,
            week: 604800, weeks: 604800,
            // pt-BR
            segundo: 1, segundos: 1,
            minuto: 60, minutos: 60,
            hora: 3600, horas: 3600,
            dia: 86400, dias: 86400,
            semana: 604800, semanas: 604800,
        };

        if (!mult[u]) {
            return null;
        }

        return parseInt(m[1], 10) * mult[u];
    }

    return {
        isEmptyTree: isEmptyTree,
        walkLeaves: walkLeaves,
        compile: compile,
        decompile: decompile,
        normalizeTree: normalizeTree,
        WAKE_SIGNAL: WAKE_SIGNAL,
        WAKE_TIMER: WAKE_TIMER,
        WAKE_ENTITY: WAKE_ENTITY,
        WAKE_MANUAL: WAKE_MANUAL,
    };
});
