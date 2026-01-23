/**
 * Build script for FullCalendar Scheduler plugins
 * 
 * Run with: node client/custom/modules/clinica/lib/build-scheduler.js
 * 
 * This creates a single bundled file with deferred execution
 * to ensure FullCalendar.Internal is available before plugins load.
 */

const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const baseDir = path.dirname(__filename);
const nodeModulesDir = path.resolve(baseDir, '../../../../../node_modules');

// Check if packages exist
// We need daygrid and timegrid as separate plugins because the scheduler plugins
// expect FullCalendar.DayGrid and FullCalendar.TimeGrid to exist as separate exports
const packages = [
    '@fullcalendar/daygrid',
    '@fullcalendar/timegrid',
    '@fullcalendar/premium-common',
    '@fullcalendar/resource',
    '@fullcalendar/resource-daygrid',
    '@fullcalendar/resource-timegrid'
];

let missingPackages = [];
packages.forEach(pkg => {
    const pkgPath = path.join(nodeModulesDir, pkg);
    if (!fs.existsSync(pkgPath)) {
        missingPackages.push(pkg);
    }
});

if (missingPackages.length > 0) {
    console.error('Missing packages:', missingPackages.join(', '));
    console.error('Please install them manually:');
    console.error(`  npm install ${missingPackages.join(' ')}`);
    process.exit(1);
}

console.log('Building FullCalendar Scheduler plugins bundle...');

// Ensure directories exist
if (!fs.existsSync(path.join(baseDir, 'original'))) {
    fs.mkdirSync(path.join(baseDir, 'original'), { recursive: true });
}

// Files to bundle in order (dependencies first)
// The scheduler plugins require FullCalendar.DayGrid and FullCalendar.TimeGrid to exist
const pluginFiles = [
    { pkg: '@fullcalendar/daygrid', path: path.join(nodeModulesDir, '@fullcalendar/daygrid/index.global.min.js') },
    { pkg: '@fullcalendar/timegrid', path: path.join(nodeModulesDir, '@fullcalendar/timegrid/index.global.min.js') },
    { pkg: '@fullcalendar/premium-common', path: path.join(nodeModulesDir, '@fullcalendar/premium-common/index.global.min.js') },
    { pkg: '@fullcalendar/resource', path: path.join(nodeModulesDir, '@fullcalendar/resource/index.global.min.js') },
    { pkg: '@fullcalendar/resource-daygrid', path: path.join(nodeModulesDir, '@fullcalendar/resource-daygrid/index.global.min.js') },
    { pkg: '@fullcalendar/resource-timegrid', path: path.join(nodeModulesDir, '@fullcalendar/resource-timegrid/index.global.min.js') },
];

// Read all plugin contents and create string-based deferred loading
let pluginStrings = [];

pluginFiles.forEach((file, index) => {
    if (fs.existsSync(file.path)) {
        const content = fs.readFileSync(file.path, 'utf8');
        // Escape the content for embedding in a string
        const escaped = JSON.stringify(content);
        pluginStrings.push({ pkg: file.pkg, content: escaped });
        console.log(`Added: ${file.pkg}`);
    } else {
        console.error(`Error: Could not find ${file.path}`);
    }
});

// Create bundle that uses Function constructor to defer execution
let bundleContent = `/*!
 * FullCalendar Scheduler Bundle
 * Contains: premium-common, resource, resource-daygrid, resource-timegrid
 * Built for EspoCRM/Clinica module
 * 
 * This bundle defers plugin execution until FullCalendar.Internal is available.
 */
(function(global) {
    'use strict';
    
    function loadPlugins() {
        if (typeof global.FullCalendar === 'undefined') {
            console.error('FullCalendar Scheduler: FullCalendar is not defined');
            return false;
        }
        
        if (!global.FullCalendar.Internal) {
            console.error('FullCalendar Scheduler: FullCalendar.Internal is not available');
            return false;
        }
        
        // Execute plugins in order using Function constructor to defer evaluation
        var plugins = [
`;

pluginStrings.forEach((plugin, index) => {
    bundleContent += `            { name: '${plugin.pkg}', code: ${plugin.content} }`;
    if (index < pluginStrings.length - 1) {
        bundleContent += ',';
    }
    bundleContent += '\n';
});

bundleContent += `        ];
        
        for (var i = 0; i < plugins.length; i++) {
            try {
                // Use Function constructor to execute in global scope
                var fn = new Function(plugins[i].code);
                fn.call(global);
            } catch (e) {
                console.error('FullCalendar Scheduler: Error loading ' + plugins[i].name, e);
                return false;
            }
        }
        
        return true;
    }
    
    // Execute immediately - FullCalendar should already be loaded
    var success = loadPlugins();
    
    // Export success status
    global.FullCalendarSchedulerLoaded = success;
    
})(typeof window !== 'undefined' ? window : this);
`;

// Write the bundled file
const bundlePath = path.join(baseDir, 'fullcalendar-scheduler-bundle.js');
const bundleOriginalPath = path.join(baseDir, 'original/fullcalendar-scheduler-bundle.js');

fs.writeFileSync(bundlePath, bundleContent);
fs.writeFileSync(bundleOriginalPath, bundleContent);

console.log(`\nCreated bundle: ${bundlePath}`);
console.log('Build complete!');
console.log('');
console.log('IMPORTANT: Make sure you have a valid FullCalendar Scheduler license');
console.log('for commercial use. See https://fullcalendar.io/pricing');
