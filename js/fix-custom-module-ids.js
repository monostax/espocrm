/************************************************************************
 * Post-process transpiled custom module files to add namespace prefix
 * 
 * The espo-frontend-build-tools Transpiler generates module IDs in the
 * format "modules/{mod}/path" for internal modules, but custom modules
 * need the format "{mod}:path".
 * 
 * This script fixes the define() calls in transpiled files.
 ************************************************************************/

const fs = require("fs");
const path = require("path");
const { globSync } = require("glob");

const customModulesPath = "custom/Espo/Modules";
const clientModulesPath = "client/custom/modules";

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
            "Resources/module.json"
        );

        if (fs.existsSync(modulePath)) {
            try {
                const moduleConfig = JSON.parse(
                    fs.readFileSync(modulePath, "utf8")
                );

                if (moduleConfig.jsTranspiled) {
                    const moduleName = moduleDir.replace(
                        /([A-Z])/g,
                        (match, p1, offset) => {
                            return offset > 0
                                ? "-" + p1.toLowerCase()
                                : p1.toLowerCase();
                        }
                    );

                    const clientPath = path.join(clientModulesPath, moduleName);
                    const transpiledPath = path.join(
                        clientPath,
                        "lib/transpiled"
                    );

                    if (fs.existsSync(transpiledPath)) {
                        modules.push({
                            name: moduleDir,
                            moduleName: moduleName,
                            transpiledPath: transpiledPath,
                        });
                    }
                }
            } catch (e) {
                console.error(
                    `Error reading module.json for ${moduleDir}:`,
                    e.message
                );
            }
        }
    }

    return modules;
}

function fixModuleIds(module) {
    const files = globSync(
        path.join(module.transpiledPath, "**/*.js").replace(/\\/g, "/")
    ).filter(f => !f.endsWith(".map"));

    let fixedCount = 0;

    for (const file of files) {
        let content = fs.readFileSync(file, "utf-8");
        const originalContent = content;
        
        // Match define("path", ...) or define("modules/{moduleName}/path", ...)
        // and replace with define("{moduleName}:path", ...)
        
        // Pattern 1: define("modules/{moduleName}/path", ...)
        const pattern1 = new RegExp(
            `define\\("modules/${module.moduleName}/([^"]+)"`,
            "g"
        );
        content = content.replace(pattern1, (match, pathPart) => {
            return `define("${module.moduleName}:${pathPart}"`;
        });
        
        // Pattern 2: define("path", ...) where path doesn't contain ':'
        const pattern2 = /define\("([^":]+)",/g;
        content = content.replace(pattern2, (match, pathPart) => {
            // Only replace if it doesn't already have a namespace
            if (pathPart.includes(':')) {
                return match;
            }
            return `define("${module.moduleName}:${pathPart}",`;
        });

        if (content !== originalContent) {
            fs.writeFileSync(file, content, "utf-8");
            fixedCount++;
        }
    }

    return fixedCount;
}

const modules = getTranspiledModules();

if (modules.length === 0) {
    console.log("\n  No transpiled custom modules found.\n");
    process.exit(0);
}

console.log(`\n  Fixing module IDs for ${modules.length} custom module(s):`);

let totalFixed = 0;

for (const module of modules) {
    const fixedCount = fixModuleIds(module);
    
    if (fixedCount > 0) {
        console.log(
            `    ✓ ${module.name} (${module.moduleName}): fixed ${fixedCount} module ID(s)`
        );
        totalFixed += fixedCount;
    }
}

console.log(`\n  Total: ${totalFixed} module ID(s) fixed\n`);

