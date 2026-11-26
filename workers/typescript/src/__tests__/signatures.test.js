import { describe, expect, it } from "vitest";
import ts from "typescript";

import { MAX_PARAMS, MAX_TYPE_LENGTH, signatureOf } from "../signatures.js";

/** The signature of the first declaration named `target` in a source text. */
function signature(text, target = "target") {
    const sourceFile = ts.createSourceFile(
        "a.ts",
        text,
        ts.ScriptTarget.Latest,
        true,
    );
    let found;
    const visit = (node) => {
        if (found === undefined && node.name?.getText(sourceFile) === target)
            found = node;
        ts.forEachChild(node, visit);
    };
    visit(sourceFile);
    return signatureOf(found, sourceFile);
}

describe("signatureOf", () => {
    it("lists parameters with the types written and the declared return", () => {
        expect(
            signature(
                "function target(root: string, limit?: number, mode = 1, ...rest: string[]): Promise<void> {}",
            ),
        ).toEqual({
            params: [
                { name: "root", type: "string" },
                { name: "limit", type: "number", optional: true },
                { name: "mode", optional: true },
                { name: "rest", type: "string[]", variadic: true },
            ],
            returns: "Promise<void>",
        });
    });

    it("infers nothing: no annotation, no type", () => {
        expect(signature("function target(a, b) { return a + b; }")).toEqual({
            params: [{ name: "a" }, { name: "b" }],
        });
        expect(signature("function target() {}")).toEqual({ params: [] });
    });

    it("reads methods, signatures, arrows and function expressions alike", () => {
        const expected = {
            params: [{ name: "id", type: "number" }],
            returns: "User",
        };

        expect(
            signature("class A { target(id: number): User { return x; } }"),
        ).toEqual(expected);
        expect(signature("interface A { target(id: number): User; }")).toEqual(
            expected,
        );
        expect(signature("const target = (id: number): User => x;")).toEqual(
            expected,
        );
        expect(
            signature(
                "const target = function (id: number): User { return x; };",
            ),
        ).toEqual(expected);
        expect(
            signature("class A { target = (id: number): User => x; }"),
        ).toEqual(expected);
        expect(
            signature("const target = ((id: number): User => x) as Handler;"),
        ).toEqual(expected);
    });

    it("has none for a declaration that holds no readable function", () => {
        expect(signature("const target = debounce(() => {});")).toBeUndefined();
        expect(signature("class A { target = 1; }")).toBeUndefined();
        expect(
            signature("interface A { target: () => void; }"),
        ).toBeUndefined();
    });

    it("names a destructured parameter by its shape and leaves `this` out", () => {
        expect(
            signature(
                "function target(this: Window, { a, b }: Options, [first]: string[]) {}",
            ),
        ).toEqual({
            params: [
                { name: "{…}", type: "Options" },
                { name: "[…]", type: "string[]" },
            ],
        });
    });
});

describe("signatureOf bounds", () => {
    it("puts a type on one line", () => {
        expect(
            signature(
                "function target(options: {\n    a: string;\n\tb: number;\u2028}): void {}",
            ).params[0].type,
        ).toBe("{ a: string; b: number; }");
    });

    it("cuts a long type and says the signature is truncated", () => {
        const long = `{ ${"field: string; ".repeat(20)}}`;

        const cut = signature(
            `function target(a: ${long}, b: string): ${long} {}`,
        );

        expect(cut.params[0].type).toHaveLength(MAX_TYPE_LENGTH);
        expect(cut.params[0].type.endsWith("…")).toBe(true);
        expect(cut.params[1]).toEqual({ name: "b", type: "string" });
        expect(cut.returns).toHaveLength(MAX_TYPE_LENGTH);
        expect(cut.truncated).toBe(true);
        expect(
            signature(`function target(a: ${"x".repeat(MAX_TYPE_LENGTH)}) {}`),
        ).toEqual({
            params: [{ name: "a", type: "x".repeat(MAX_TYPE_LENGTH) }],
        });
    });

    it("lists at most the first parameters of a very long list", () => {
        const names = Array.from({ length: MAX_PARAMS + 3 }, (_, i) => `p${i}`);

        const cut = signature(`function target(${names.join(", ")}) {}`);

        expect(cut.params.map((param) => param.name)).toEqual(
            names.slice(0, MAX_PARAMS),
        );
        expect(cut.truncated).toBe(true);
        expect(
            signature(
                `function target(${names.slice(0, MAX_PARAMS).join(", ")}) {}`,
            ).truncated,
        ).toBeUndefined();
    });

    it("keeps a function's type parameters as written", () => {
        expect(
            signature(
                "class A { target<U extends string, V = number>(x: U): V { return y; } }",
            ),
        ).toEqual({
            params: [{ name: "x", type: "U" }],
            returns: "V",
            type_params: ["U extends string", "V = number"],
        });
        expect(
            signature("const target = <T,>(x: T): T => x;").type_params,
        ).toEqual(["T"]);
        expect(signature("function target() {}").type_params).toBeUndefined();
    });
});
