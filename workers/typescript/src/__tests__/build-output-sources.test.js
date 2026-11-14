import { describe, expect, it } from "vitest";
import ts from "typescript";

import { buildOutputSources } from "../build-output-sources.js";

const OUTPUTS = [{ outDir: "/project/dist", rootDir: "/project/src" }];

/** A host that knows some files and remembers every path it was asked about. */
function hostWith(...present) {
    const asked = [];
    return {
        asked,
        fileExists: (file) => {
            asked.push(file);
            return present.includes(file);
        },
    };
}

describe("buildOutputSources", () => {
    it("reads a failed lookup in a known outDir as the source it is built from", () => {
        const host = hostWith("/project/src/shared/x.ts");
        const failed = [
            "/project/node_modules/shared/x.ts",
            "/project/dist/shared/x.d.ts",
        ];

        const found = buildOutputSources(
            OUTPUTS,
            host,
        )({
            resolvedModule: undefined,
            failedLookupLocations: failed,
        });

        expect(found).toEqual({
            resolvedModule: {
                resolvedFileName: "/project/src/shared/x.ts",
                extension: ts.Extension.Ts,
                isExternalLibraryImport: false,
            },
            failedLookupLocations: failed,
        });
    });

    it("reads a module resolved into an outDir as its source", () => {
        const host = hostWith("/project/src/y.tsx");

        const found = buildOutputSources(
            OUTPUTS,
            host,
        )({
            resolvedModule: { resolvedFileName: "/project/dist/y.js" },
            failedLookupLocations: [],
        });

        expect(found.resolvedModule.resolvedFileName).toBe(
            "/project/src/y.tsx",
        );
        expect(found.resolvedModule.extension).toBe(ts.Extension.Tsx);
    });

    it("finds nothing when no lookup lies in an outDir or the source is absent", () => {
        const host = hostWith();
        const source = buildOutputSources(OUTPUTS, host);

        expect(
            source({
                resolvedModule: undefined,
                failedLookupLocations: ["/project/node_modules/a/index.ts"],
            }),
        ).toBeUndefined();
        expect(
            source({
                resolvedModule: undefined,
                failedLookupLocations: ["/project/dist/gone.js"],
            }),
        ).toBeUndefined();
        expect(source({ resolvedModule: undefined })).toBeUndefined();
    });

    it("prefers the first output directory that holds the location", () => {
        const host = hostWith(
            "/project/shared/x.ts",
            "/project/src/shared/x.ts",
        );
        const outputs = [
            { outDir: "/project/dist/shared", rootDir: "/project/shared" },
            ...OUTPUTS,
        ];

        const found = buildOutputSources(
            outputs,
            host,
        )({
            resolvedModule: undefined,
            failedLookupLocations: ["/project/dist/shared/x.js"],
        });

        expect(found.resolvedModule.resolvedFileName).toBe(
            "/project/shared/x.ts",
        );
    });
});

describe("buildOutputSources over a list the compiler shares", () => {
    it("checks only the lookups appended since it last saw a shared list", () => {
        // The compiler shares one list between every file importing a module
        // and appends each directory's lookups to it.
        const host = hostWith();
        const source = buildOutputSources(OUTPUTS, host);
        const failed = ["/project/dist/a/one.js", "/project/dist/a/two.js"];
        const result = {
            resolvedModule: undefined,
            failedLookupLocations: failed,
        };

        expect(source(result)).toBeUndefined();
        const afterFirst = host.asked.length;
        expect(afterFirst).toBe(8);

        expect(source(result)).toBeUndefined();
        expect(host.asked.length).toBe(afterFirst);

        failed.push("/project/dist/b/three.js");
        expect(source(result)).toBeUndefined();
        expect(host.asked.slice(afterFirst)).toEqual([
            "/project/src/b/three.ts",
            "/project/src/b/three.tsx",
            "/project/src/b/three.mts",
            "/project/src/b/three.cts",
        ]);
    });

    it("finds a source among the appended lookups, every time it is asked", () => {
        const host = hostWith("/project/src/b/three.ts");
        const source = buildOutputSources(OUTPUTS, host);
        const failed = ["/project/dist/a/one.js"];
        const result = {
            resolvedModule: undefined,
            failedLookupLocations: failed,
        };

        expect(source(result)).toBeUndefined();
        failed.push("/project/dist/b/three.js", "/project/dist/b/four.js");

        const expected = "/project/src/b/three.ts";
        expect(source(result).resolvedModule.resolvedFileName).toBe(expected);
        expect(source(result).resolvedModule.resolvedFileName).toBe(expected);
    });

    it("still checks a resolved module each time, whatever the list held", () => {
        const host = hostWith("/project/src/z.ts");
        const source = buildOutputSources(OUTPUTS, host);
        const failed = ["/project/dist/a/one.js"];

        expect(
            source({
                resolvedModule: undefined,
                failedLookupLocations: failed,
            }),
        ).toBeUndefined();

        const found = source({
            resolvedModule: { resolvedFileName: "/project/dist/z.js" },
            failedLookupLocations: failed,
        });
        expect(found.resolvedModule.resolvedFileName).toBe("/project/src/z.ts");
    });

    it("does not carry what it saw into another program's resolver", () => {
        const failed = ["/project/dist/a/one.js"];
        const result = {
            resolvedModule: undefined,
            failedLookupLocations: failed,
        };
        buildOutputSources(OUTPUTS, hostWith())(result);

        const later = hostWith("/project/src/a/one.ts");

        expect(
            buildOutputSources(OUTPUTS, later)(result).resolvedModule
                .resolvedFileName,
        ).toBe("/project/src/a/one.ts");
    });
});
