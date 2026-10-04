'use strict';

// Grunt and liftup need synchronous ancestor lookup. Use maintained minimatch
// rather than findup-sync's micromatch/braces dependency (GHSA-vfj7-8cjw-p6xm).
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {minimatch} = require('minimatch');

module.exports = function findupSync(patterns, options = {}) {
    if (typeof patterns === 'string') {
        patterns = [patterns];
    }
    if (!Array.isArray(patterns)) {
        throw new TypeError('findup-sync expects a string or array.');
    }

    const cwd = options.cwd || process.cwd();
    let directory = path.resolve(cwd.replace(/^~(?=$|\/)/, os.homedir()));

    while (true) {
        let entries;
        for (const pattern of patterns) {
            // Check literal paths first, including paths containing glob syntax.
            const candidate = path.resolve(directory, pattern);
            try {
                if (fs.statSync(candidate).isFile()) {
                    return candidate;
                }
            } catch (error) {
                if (!['ENOENT', 'ENOTDIR', 'EACCES'].includes(error.code)) throw error;
            }
            if (!entries) {
                try {
                    entries = fs.readdirSync(directory);
                } catch (error) {
                    if (!['ENOENT', 'ENOTDIR', 'EACCES'].includes(error.code)) throw error;
                    entries = [];
                }
            }
            for (const entry of entries) {
                const filename = path.join(directory, entry);
                if (minimatch(entry, pattern, options) || minimatch(filename, pattern, options)) {
                    return filename;
                }
            }
        }
        const parent = path.dirname(directory);
        if (parent === directory) return null;
        directory = parent;
    }
};
