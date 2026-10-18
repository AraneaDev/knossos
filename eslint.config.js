import js from "@eslint/js";
import tseslint from "typescript-eslint";

export default [
    {
        ignores: [
            "node_modules/**",
            "workers/**/node_modules/**",
            "vendor/**",
            "tools/capture/out/**",
        ],
    },
    js.configs.recommended,
    ...tseslint.configs.recommended.map((config) => ({
        ...config,
        files: ["hooks/**/*.{ts,tsx}", "web/**/*.ts"],
    })),
    {
        files: ["tools/*.mjs"],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: "module",
            globals: { console: "readonly", process: "readonly" },
        },
    },
    {
        files: ["tools/capture/*.mjs"],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: "module",
            globals: {
                console: "readonly",
                process: "readonly",
                setTimeout: "readonly",
                URL: "readonly",
            },
        },
    },
    {
        files: ["tools/eval/**/*.mjs"],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: "module",
            globals: {
                console: "readonly",
                process: "readonly",
                setTimeout: "readonly",
                clearTimeout: "readonly",
                URL: "readonly",
            },
        },
    },
    {
        // The web pane's page: plain modules the browser loads as they are.
        files: ["web/public/*.mjs"],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: "module",
            globals: {
                Blob: "readonly",
                clearTimeout: "readonly",
                crypto: "readonly",
                CSS: "readonly",
                document: "readonly",
                EventSource: "readonly",
                fetch: "readonly",
                HTMLInputElement: "readonly",
                location: "readonly",
                matchMedia: "readonly",
                navigator: "readonly",
                performance: "readonly",
                sessionStorage: "readonly",
                setTimeout: "readonly",
                URL: "readonly",
                URLSearchParams: "readonly",
                window: "readonly",
            },
        },
    },
    {
        files: ["web/*.spec.mjs"],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: "module",
            globals: { URL: "readonly" },
        },
    },
    {
        files: ["workers/typescript/**/*.js"],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: "module",
            globals: {
                Buffer: "readonly",
                console: "readonly",
                process: "readonly",
                setTimeout: "readonly",
            },
        },
        rules: {
            complexity: ["error", 29],
            "max-lines-per-function": [
                "error",
                { max: 101, skipBlankLines: true, skipComments: true },
            ],
        },
    },
];
