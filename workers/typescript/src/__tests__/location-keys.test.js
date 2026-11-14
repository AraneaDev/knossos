import { describe, expect, it } from "vitest";

import { LocationKeys, inputHashKey } from "../location-keys.js";

describe("LocationKeys", () => {
    it("gives a location inside the root its absolute path and relative key", () => {
        const keys = new LocationKeys("/project");

        expect(keys.of("/project/src/a.ts")).toEqual({
            absolute: "/project/src/a.ts",
            key: "src/a.ts",
        });
    });

    it("normalises the location before keying it", () => {
        const keys = new LocationKeys("/project");

        expect(keys.of("/project/src/../lib/b.ts")).toEqual({
            absolute: "/project/lib/b.ts",
            key: "lib/b.ts",
        });
    });

    it("has no key for the root itself or anything outside it", () => {
        const keys = new LocationKeys("/project");

        expect(keys.of("/project").key).toBeNull();
        expect(keys.of("/elsewhere/package.json").key).toBeNull();
    });

    it("works a location out once however often it is asked for", () => {
        const keys = new LocationKeys("/project");

        const first = keys.of("/project/node_modules/dep/package.json");

        expect(keys.of("/project/node_modules/dep/package.json")).toBe(first);
        expect(keys.of("/project/node_modules/other/package.json")).not.toBe(
            first,
        );
    });

    it("keeps the keys of one root apart from another's", () => {
        const outer = new LocationKeys("/project");
        const inner = new LocationKeys("/project/app");

        expect(outer.of("/project/app/a.ts").key).toBe("app/a.ts");
        expect(inner.of("/project/app/a.ts").key).toBe("a.ts");
    });
});

describe("inputHashKey", () => {
    it("is the path relative to the root, or null outside it", () => {
        expect(inputHashKey("/project", "/project/src/a.ts")).toBe("src/a.ts");
        expect(inputHashKey("/project", "/project")).toBeNull();
        expect(inputHashKey("/project", "/other/a.ts")).toBeNull();
    });
});
