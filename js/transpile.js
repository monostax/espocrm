/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2025 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

const { Transpiler, Bundler } = require("espo-frontend-build-tools");
const fs = require("fs");
const path = require("path");

let file;

let fIndex = process.argv.findIndex((item) => item === "-f");

if (fIndex > 0) {
    file = process.argv.at(fIndex + 1);

    if (!file) {
        throw new Error(`No file specified.`);
    }
}

// Transpile core and CRM module
const transpiler1 = new Transpiler({
    file: file,
});

const transpiler2 = new Transpiler({
    mod: "crm",
    path: "client/modules/crm",
    file: file,
});

const result1 = transpiler1.process();
const result2 = transpiler2.process();

let count = result1.transpiled.length + result2.transpiled.length;
let copiedCount = result1.copied.length + result2.copied.length;

// Discover and transpile custom modules with jsTranspiled or bundled flag
const customModulesPath = "custom/Espo/Modules";
const clientModulesPath = "client/custom/modules";

if (fs.existsSync(customModulesPath)) {
    const moduleDirs = fs.readdirSync(customModulesPath);

    for (const moduleDir of moduleDirs) {
        const modulePath = path.join(
            customModulesPath,
            moduleDir,
            "Resources/module.json"
        );

        if (fs.existsSync(modulePath)) {
            try {
                const moduleConfig = JSON.parse(
                    fs.readFileSync(modulePath, "utf8")
                );

                if (moduleConfig.jsTranspiled || moduleConfig.bundled) {
                    const moduleName = moduleDir.replace(
                        /([A-Z])/g,
                        (match, p1, offset) => {
                            return offset > 0
                                ? "-" + p1.toLowerCase()
                                : p1.toLowerCase();
                        }
                    );

                    const clientPath = path.join(clientModulesPath, moduleName);

                    if (fs.existsSync(clientPath)) {
                        const transpiler = new Transpiler({
                            path: clientPath,
                            destDir: path.join(clientPath, "lib/transpiled"),
                            file: file,
                        });

                        const result = transpiler.process();
                        count += result.transpiled.length;
                        copiedCount += result.copied.length;

                        // Fix module IDs to add namespace prefix for custom modules
                        const transpiledFiles = require("glob")
                            .globSync(
                                path.join(clientPath, "lib/transpiled/**/*.js")
                                    .replace(/\\/g, "/")
                            )
                            .filter((f) => !f.endsWith(".map"));

                        for (const file of transpiledFiles) {
                            let content = fs.readFileSync(file, "utf-8");
                            const originalContent = content;

                            // Pattern 1: define("modules/{moduleName}/path", ...)
                            const pattern1 = new RegExp(
                                `define\\("modules/${moduleName}/([^"]+)"`,
                                "g"
                            );
                            content = content.replace(pattern1, (match, pathPart) => {
                                return `define("${moduleName}:${pathPart}"`;
                            });

                            // Pattern 2: define("path", ...) where path doesn't contain ':'
                            const pattern2 = /define\("([^":]+)",/g;
                            content = content.replace(pattern2, (match, pathPart) => {
                                if (pathPart.includes(":")) {
                                    return match;
                                }
                                return `define("${moduleName}:${pathPart}",`;
                            });

                            if (content !== originalContent) {
                                fs.writeFileSync(file, content, "utf-8");
                            }
                        }

                        // Bundle if needed
                        if (moduleConfig.bundled) {
                            const bundleConfigPath = path.join(
                                clientPath,
                                "bundle-config.json"
                            );

                            if (fs.existsSync(bundleConfigPath)) {
                                const bundleConfig = JSON.parse(
                                    fs.readFileSync(bundleConfigPath, "utf8")
                                );
                                const bundler = new Bundler(bundleConfig);
                                const bundleResult = bundler.bundle();

                                const libPath = path.join(clientPath, "lib");
                                if (!fs.existsSync(libPath)) {
                                    fs.mkdirSync(libPath, { recursive: true });
                                }

                                fs.writeFileSync(
                                    path.join(libPath, "init.js"),
                                    bundleResult.main || "",
                                    "utf8"
                                );
                            }
                        }
                    }
                }
            } catch (e) {
                console.error(
                    `Error processing custom module ${moduleDir}:`,
                    e.message
                );
            }
        }
    }
}

console.log(`\n  transpiled: ${count}, copied: ${copiedCount}`);
