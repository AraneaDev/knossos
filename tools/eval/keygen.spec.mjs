import { describe, expect, it } from "vitest";
import { diffAnswers, knossosAnswer } from "./keygen.mjs";

const usages = {
    data: {
        usages: [
            {
                kind: "calls",
                source: { canonical_name: "App\\Billing::summarise" },
                path: "app/Billing.php",
            },
            {
                kind: "imports",
                source: { canonical_name: "App\\Other" },
                path: "app/Other.php",
            },
            {
                kind: "dispatches",
                source: { canonical_name: "App\\Jobs\\Send::handle" },
                path: "app/Jobs/Send.php",
            },
        ],
    },
};
const impact = {
    data: {
        dependants: [
            {
                node: {
                    canonical_name: "App\\Http\\Show::__invoke",
                    via: { evidence: { path: "app/Http/Show.php" } },
                },
            },
        ],
    },
};

describe("knossosAnswer", () => {
    it("reads callers from call and dispatch usages only", () => {
        expect(knossosAnswer("callers", "symbol", usages)).toEqual([
            "App\\Billing::summarise",
            "App\\Jobs\\Send::handle",
        ]);
    });
    it("reads impact as symbols and dependents as files", () => {
        expect(knossosAnswer("impact", "symbol", impact)).toEqual([
            "App\\Http\\Show::__invoke",
        ]);
        expect(knossosAnswer("dependents", "file", impact)).toEqual([
            "app/Http/Show.php",
        ]);
    });
    it("has no CLI answer for location and flow", () => {
        expect(knossosAnswer("location", "file", {})).toBeNull();
        expect(knossosAnswer("flow", "symbol", {})).toBeNull();
    });
});

describe("diffAnswers", () => {
    it("lists what Knossos missed and what it added, normalised", () => {
        const opts = { unit: "symbol", language: "php" };
        expect(
            diffAnswers(
                ["App\\Billing::summarise", "App\\X::y"],
                ["\\App\\Billing::summarise", "App\\Z::w"],
                opts,
            ),
        ).toEqual({
            missing: ["x::y"],
            extra: ["z::w"],
        });
    });
});
