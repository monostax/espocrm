/************************************************************************
 * Transpile and bundle all custom modules that have jsTranspiled/bundled: true
 ************************************************************************/

const { Transpiler, Bundler } = require("espo-frontend-build-tools");
const fs = require("fs");
const path = require("path");

const customModulesPath = "custom/Espo/Modules";
const clientModulesPath = "client/custom/modules";

// Find all custom modules with jsTranspiled or bundled flag
function getTranspiledModules() {
    const modules = [];

    if (!fs.existsSync(customModulesPath)) {
        return modules;
    }

    const moduleDirs = fs.readdirSync(customModulesPath);

    for (const moduleDir of moduleDirs) {
        const modulePath = path.join(
            customModulesPath,
            moduleDir,
            "Resources/module.json",
        );

        if (fs.existsSync(modulePath)) {
            try {
                const moduleConfig = JSON.parse(
                    fs.readFileSync(modulePath, "utf8"),
                );

                if (moduleConfig.jsTranspiled || moduleConfig.bundled) {
                    const moduleName = moduleDir.replace(
                        /([A-Z])/g,
                        (match, p1, offset) => {
                            return offset > 0
                                ? "-" + p1.toLowerCase()
                                : p1.toLowerCase();
                        },
                    );

                    const clientPath = path.join(clientModulesPath, moduleName);

                    if (fs.existsSync(clientPath)) {
                        modules.push({
                            name: moduleDir,
                            moduleName: moduleName,
                            clientPath: clientPath,
                            bundled: moduleConfig.bundled || false,
                            jsTranspiled: moduleConfig.jsTranspiled || false,
                        });
                    }
                }
            } catch (e) {
                console.error(
                    `Error reading module.json for ${moduleDir}:`,
                    e.message,
                );
            }
        }
    }

    return modules;
}

// Transpile all modules
const modules = getTranspiledModules();

if (modules.length === 0) {
    console.log("\n  No custom modules require transpilation.");
    process.exit(0);
}

console.log(
    `\n  Found ${modules.length} custom module(s) requiring transpilation:`,
);

let totalTranspiled = 0;
let totalCopied = 0;

for (const module of modules) {
    console.log(`    - ${module.name} (${module.moduleName})`);

    try {
        // Transpile module
        const transpiler = new Transpiler({
            path: module.clientPath,
            destDir: path.join(module.clientPath, "lib/transpiled"),
        });

        const result = transpiler.process();

        totalTranspiled += result.transpiled.length;
        totalCopied += result.copied.length;

        let bundleMsg = "";

        // Bundle if needed
        if (module.bundled) {
            try {
                const bundleConfigPath = path.join(
                    module.clientPath,
                    "bundle-config.json",
                );

                if (fs.existsSync(bundleConfigPath)) {
                    const bundleConfig = JSON.parse(
                        fs.readFileSync(bundleConfigPath, "utf8"),
                    );
                    const bundler = new Bundler(bundleConfig);
                    const bundleResult = bundler.bundle();

                    // Write init.js
                    const libPath = path.join(module.clientPath, "lib");
                    if (!fs.existsSync(libPath)) {
                        fs.mkdirSync(libPath, { recursive: true });
                    }

                    fs.writeFileSync(
                        path.join(libPath, "init.js"),
                        bundleResult.main || "",
                        "utf8",
                    );
                    bundleMsg = " (bundled)";
                }
            } catch (e) {
                console.error(
                    `      ⚠ Error bundling ${module.name}:`,
                    e.message,
                );
            }
        }

        console.log(
            `      ✓ transpiled: ${result.transpiled.length}, copied: ${result.copied.length}${bundleMsg}`,
        );
    } catch (e) {
        console.error(`      ✗ Error transpiling ${module.name}:`, e.message);
    }
}

console.log(
    `\n  Total: transpiled:  ${totalTranspiled}, copied: ${totalCopied}\n`,
);

// Fix module IDs to add namespace prefix
console.log("  Fixing module IDs...");

let totalFixed = 0;

for (const module of modules) {
    const files = require("glob")
        .globSync(
            require("path")
                .join(module.clientPath, "lib/transpiled/**/*.js")
                .replace(/\\/g, "/"),
        )
        .filter((f) => !f.endsWith(".map"));

    for (const file of files) {
        let content = fs.readFileSync(file, "utf-8");
        const originalContent = content;

        // Pattern 1: define("modules/{moduleName}/path", ...)
        const pattern1 = new RegExp(
            `define\\(\\s*([\"'])modules/${module.moduleName}/([^\"']+)\\1`,
            "g",
        );
        content = content.replace(pattern1, (match, quote, pathPart) => {
            return `define(${quote}${module.moduleName}:${pathPart}${quote}`;
        });

        // Pattern 2: define("path", ...) where path doesn't contain ':'
        const pattern2 = /define\(\s*(["'])([^:"']+)\1\s*,/g;
        content = content.replace(pattern2, (match, quote, pathPart) => {
            if (pathPart.includes(":")) {
                return match;
            }
            return `define(${quote}${module.moduleName}:${pathPart}${quote},`;
        });

        if (content !== originalContent) {
            fs.writeFileSync(file, content, "utf-8");
            totalFixed++;
        }
    }
}

console.log(`  Fixed ${totalFixed} module ID(s)\n`);

// Validate that all transpiled files have the correct namespace prefix.
// If any file has a bare define() without a prefix, the build must fail
// so broken modules never reach production silently.
console.log("  Validating module IDs...");

let validationErrors = 0;

for (const module of modules) {
    const files = require("glob")
        .globSync(
            path
                .join(module.clientPath, "lib/transpiled/**/*.js")
                .replace(/\\/g, "/"),
        )
        .filter((f) => !f.endsWith(".map"));

    for (const file of files) {
        const content = fs.readFileSync(file, "utf-8");
        const defineMatch = content.match(/(^|\n)\s*define\(\s*["']([^"']+)["']/);

        if (!defineMatch) continue;

        const moduleId = defineMatch[2];

        if (!moduleId.startsWith(`${module.moduleName}:`)) {
            console.error(
                `      ✗ ${path.relative(".", file)}: define("${moduleId}") does not start with namespace prefix "${module.moduleName}:"`,
            );
            validationErrors++;
        }
    }
}

if (validationErrors > 0) {
    console.error(
        `\n  ✗ Validation failed: ${validationErrors} file(s) have unprefixed module IDs.\n` +
            `    This will cause silent loading failures in production.\n`,
    );
    process.exit(1);
}

console.log(`  ✓ All module IDs validated successfully.\n`);
