import { fileURLToPath } from "node:url";

import { defineConfig } from "vitest/config";

import budgets from "./coverage-budgets.json" with { type: "json" };

const jsxStub = fileURLToPath(
    new URL("./tools/jsx-runtime-stub.mjs", import.meta.url),
);

export default defineConfig({
    // The mod brings no React package: Claude Code compiles its JSX, and the suite resolves the runtime to a stub.
    resolve: {
        alias: {
            "react/jsx-dev-runtime": jsxStub,
            "react/jsx-runtime": jsxStub,
        },
    },
    test: {
        include: [
            "hooks/lib/**/*.spec.ts",
            "hooks/mod/**/*.spec.ts",
            "tools/*.spec.mjs",
            "tools/capture/*.spec.mjs",
            "tools/eval/*.spec.mjs",
            "web/**/*.spec.ts",
            "web/**/*.spec.mjs",
        ],
        environment: "node",
        // hooks/register.tsx is outside this measurement because the engine loads it; tools/quality type-checks it and runs register.test.ts through `claude plugin test`.
        // The web host's server side is measured with the hooks; its page (web/public) is not, as the suite has no DOM: its pure functions are tested, its wiring is not.
        // web/main.ts runs only as a process of its own (main.spec spawns it), which this measurement cannot see into; what it decides is in args.ts, life.ts and lock.ts.
        coverage: {
            provider: "v8",
            include: [
                "hooks/lib/**/*.ts",
                "hooks/mod/**/*.ts",
                "hooks/mod/**/*.tsx",
                "web/*.ts",
            ],
            exclude: [
                "**/*.spec.ts",
                "**/__tests__/**",
                "web/public/**",
                "web/main.ts",
            ],
            reportsDirectory: "coverage/mod",
            reporter: ["text-summary", "json-summary"],
            thresholds: budgets.mod,
        },
    },
});
