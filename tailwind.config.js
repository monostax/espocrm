/** @type {import('tailwindcss').Config} */
export default {
    content: [
        // Core templates
        "./client/res/templates/**/*.tpl",
        "./client/modules/**/res/templates/**/*.tpl",

        // Custom templates
        "./client/custom/**/res/templates/**/*.tpl",
        "./client/custom/**/templates/**/*.tpl",

        // JavaScript views (may contain template strings or class names)
        "./client/src/**/*.js",
        "./client/modules/**/src/**/*.js",
        "./client/custom/**/src/**/*.js",

        // HTML files
        "./html/**/*.html",

        // PHP templates (if any inline classes)
        "./custom/**/Resources/layouts/**/*.json",
    ],
};

