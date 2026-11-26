import { afterEach, describe, expect, it } from "vitest";
import fs from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";

import { TypeScriptScanner } from "../scanner.js";
import { SCAN_LIMITS } from "./support/scan-limits.mjs";

// A class, interface or object-literal type alias lists the fields a
// deserialiser fills, and an enum its cases, so the core can hold a fixture
// against them. A list that may not be the wire's whole story is marked open,
// and the core stays silent on it.

const created = [];

afterEach(() => {
    while (created.length > 0) {
        fs.rmSync(created.pop(), { recursive: true, force: true });
    }
});

/** The attributes of every class, interface, type alias and enum node, by display name. */
function scan(files) {
    const root = fs.realpathSync(
        fs.mkdtempSync(join(tmpdir(), "knossos-ts-fields-")),
    );
    created.push(root);
    for (const [path, contents] of Object.entries(files)) {
        fs.mkdirSync(dirname(join(root, path)), { recursive: true });
        fs.writeFileSync(join(root, path), contents);
    }
    const contributions = [];
    new TypeScriptScanner().scan(
        { limits: SCAN_LIMITS, root, files: Object.keys(files) },
        (c) => contributions.push(c),
    );
    return Object.fromEntries(
        contributions
            .flatMap((c) => c.nodes)
            .filter((node) =>
                ["class", "interface", "type_alias", "enum"].includes(
                    node.kind,
                ),
            )
            .map((node) => [node.display_name, node.attributes]),
    );
}

describe("field lists", () => {
    it("fields_of_an_interface_with_optional_members", () => {
        const nodes = scan({
            "src/dto.ts":
                "export interface User {\n" +
                "  id: string;\n" +
                "  nick?: string;\n" +
                "  email: string | undefined;\n" +
                "  greet(): void;\n" +
                "}\n",
        });

        expect(nodes.User.fields).toEqual([
            { name: "email", type: "string | undefined", required: false, line: 4 },
            { name: "id", type: "string", required: true, line: 2 },
            { name: "nick", type: "string", required: false, line: 3 },
        ]);
        expect(nodes.User.fields_open).toBeUndefined();
        expect(nodes.User.fields_truncated).toBeUndefined();
    });

    it("lists an object-literal type alias, and no other alias", () => {
        const nodes = scan({
            "src/dto.ts":
                "export type Point = { x: number; y?: number };\n" +
                "export type Id = string;\n" +
                "export type Either = { a: 1 } | { b: 2 };\n",
        });

        expect(nodes.Point.fields).toEqual([
            { name: "x", type: "number", required: true, line: 1 },
            { name: "y", type: "number", required: false, line: 1 },
        ]);
        expect(nodes.Id.fields).toBeUndefined();
        expect(nodes.Either.fields).toBeUndefined();
    });

    it("class_fields_with_initializers_are_optional", () => {
        const nodes = scan({
            "src/dto.ts":
                "export class Order {\n" +
                "  id!: string;\n" +
                "  count = 0;\n" +
                "  note?: string;\n" +
                "  static table = 'orders';\n" +
                "  #secret = 1;\n" +
                "  constructor(public owner: string, readonly tag: string = 'x') {}\n" +
                "  get label(): string { return this.id; }\n" +
                "  total(): number { return 0; }\n" +
                "}\n",
        });

        expect(nodes.Order.fields).toEqual([
            { name: "count", required: false, line: 3 },
            { name: "id", type: "string", required: true, line: 2 },
            { name: "note", type: "string", required: false, line: 4 },
            { name: "owner", type: "string", required: true, line: 7 },
            { name: "tag", type: "string", required: false, line: 7 },
        ]);
        expect(nodes.Order.fields_open).toBeUndefined();
    });

    it("a_resolved_base_merges_fields", () => {
        const nodes = scan({
            "src/base.ts": "export interface Base {\n  id: string;\n}\n",
            "src/dto.ts":
                "import { Base } from './base';\n" +
                "export interface User extends Base {\n  name: string;\n}\n" +
                "export class Entity { id = ''; }\n" +
                "export class Account extends Entity { owner!: string; }\n",
        });

        expect(nodes.User.fields.map((f) => f.name)).toEqual(["id", "name"]);
        expect(nodes.User.fields_open).toBeUndefined();
        expect(nodes.Account.fields.map((f) => f.name)).toEqual([
            "id",
            "owner",
        ]);
        expect(nodes.Account.fields_open).toBeUndefined();
    });

    it("an_unresolved_base_opens_the_list", () => {
        const nodes = scan({
            "src/dto.ts":
                "import { Missing } from 'not-installed';\n" +
                "export interface User extends Missing { name: string; }\n" +
                "export class Row extends Missing { id!: string; }\n",
        });

        expect(nodes.User.fields_open).toBe(true);
        expect(nodes.User.fields.map((f) => f.name)).toEqual(["name"]);
        expect(nodes.Row.fields_open).toBe(true);
    });

    it("opens the list on an index signature", () => {
        const nodes = scan({
            "src/dto.ts":
                "export interface Bag { id: string; [key: string]: unknown; }\n" +
                "export interface Tagged extends Bag { tag: string; }\n",
        });

        expect(nodes.Bag.fields_open).toBe(true);
        expect(nodes.Tagged.fields_open).toBe(true);
    });

    it("merges a resolved intersection and opens on one that does not resolve", () => {
        const nodes = scan({
            "src/dto.ts":
                "import { Missing } from 'not-installed';\n" +
                "interface Base { id: string; }\n" +
                "export type Merged = Base & { name: string };\n" +
                "export type Open = Missing & { name: string };\n" +
                "export type Generic<T> = T & { name: string };\n",
        });

        expect(nodes.Merged.fields.map((f) => f.name)).toEqual(["id", "name"]);
        expect(nodes.Merged.fields_open).toBeUndefined();
        expect(nodes.Open.fields_open).toBe(true);
        expect(nodes.Generic.fields_open).toBe(true);
    });

    it("a_decorator_opens_the_list", () => {
        const nodes = scan({
            "src/dto.ts":
                "declare function Expose(o?: object): PropertyDecorator;\n" +
                "declare function Entity(): ClassDecorator;\n" +
                "declare function Column(): PropertyDecorator;\n" +
                "export class Renamed {\n" +
                "  @Expose({ name: 'user_name' }) userName!: string;\n" +
                "}\n" +
                "@Entity()\n" +
                "export class Table { id!: number; }\n" +
                "export class Mapped { @Column() id!: number; }\n" +
                "export class Child extends Renamed { extra!: string; }\n" +
                "export class Plain { id!: number; }\n",
        });

        expect(nodes.Renamed.fields_open).toBe(true);
        expect(nodes.Table.fields_open).toBe(true);
        expect(nodes.Mapped.fields_open).toBe(true);
        expect(nodes.Child.fields_open).toBe(true);
        expect(nodes.Plain.fields_open).toBeUndefined();
    });

    it("opens an alias that intersects a decorated class or its subclass", () => {
        const nodes = scan({
            "src/dto.ts":
                "declare function Expose(o?: object): PropertyDecorator;\n" +
                "export class OneDecorated {\n" +
                "  @Expose({ name: 'user_name' }) userName!: string;\n" +
                "  plain!: string;\n" +
                "}\n" +
                "export class Sub extends OneDecorated {}\n" +
                "export class Clean { id!: string; }\n" +
                "export type AliasOfDecorated = OneDecorated & { extra: string };\n" +
                "export type AliasOfSub = { extra: string } & Sub;\n" +
                "export type AliasOfClean = Clean & { extra: string };\n",
        });

        expect(nodes.AliasOfDecorated.fields_open).toBe(true);
        expect(nodes.AliasOfSub.fields_open).toBe(true);
        expect(nodes.AliasOfClean.fields_open).toBeUndefined();
        expect(nodes.AliasOfClean.fields.map((f) => f.name)).toEqual([
            "extra",
            "id",
        ]);
    });

    it("opens a mapped type over a decorated class, whatever wraps it", () => {
        const nodes = scan({
            "src/dto.ts":
                "declare function Expose(o?: object): PropertyDecorator;\n" +
                "export class DecP {\n" +
                "  @Expose({ name: 'user_name' }) userName!: string;\n" +
                "  plain!: string;\n" +
                "}\n" +
                "export class Plain { id!: string; note?: string; }\n" +
                "export type WithOmit = { extra: string } & Omit<DecP, 'plain'>;\n" +
                "export type WithPartial = { extra: string } & Partial<DecP>;\n" +
                "export type WithPick = Pick<DecP, 'userName'> & { x: string };\n" +
                "export type Frozen = Readonly<DecP> & {};\n" +
                "export type Clean = Partial<Plain> & { x: string };\n",
        });

        for (const name of ["WithOmit", "WithPartial", "WithPick", "Frozen"]) {
            expect([name, nodes[name].fields_open]).toEqual([name, true]);
        }
        expect(nodes.Clean.fields_open).toBeUndefined();
        expect(nodes.Clean.fields).toEqual([
            { name: "id", type: "string", required: false, line: 6 },
            { name: "note", type: "string", required: false, line: 6 },
            { name: "x", type: "string", required: true, line: 11 },
        ]);
    });

    it("opens a list holding a key no source declares", () => {
        const nodes = scan({
            "src/dto.ts":
                "export type Keys = Record<'a' | 'b', string> & { id: string };\n" +
                "type Handlers = { [K in `on${'Click' | 'Hover'}`]: () => void };\n" +
                "export type Templated = Handlers & { id: string };\n" +
                "export type Inline = { [K in 'a' | 'b']: string } & { id: string };\n",
        });

        expect(nodes.Keys.fields_open).toBe(true);
        expect(nodes.Templated.fields_open).toBe(true);
        // A mapped type written in place is not an object shape: no list at all.
        expect(nodes.Inline.fields).toBeUndefined();
    });

    it("sends no list for an intersection that resolves to a union", () => {
        const nodes = scan({
            "src/dto.ts":
                "export type Disc = { id: string } & ({ kind: 'a'; x: string } | { kind: 'b'; y: string });\n" +
                "type Variant = { kind: 'a'; x: string } | { kind: 'b'; y: string };\n" +
                "export type Named = { id: string } & Variant;\n",
        });

        expect(nodes.Disc.fields).toBeUndefined();
        expect(nodes.Named.fields).toBeUndefined();
    });

    it("a_64_field_class_is_cut_and_marked", () => {
        const names = Array.from(
            { length: 70 },
            (_, i) => `f${String(i).padStart(2, "0")}`,
        );
        const nodes = scan({
            "src/dto.ts":
                "export class Wide {\n" +
                names.map((name) => `  ${name}!: string;\n`).join("") +
                "}\n",
        });

        expect(nodes.Wide.fields).toHaveLength(64);
        expect(nodes.Wide.fields[63].name).toBe("f63");
        expect(nodes.Wide.fields_truncated).toBe(true);
    });
});

describe("enum cases", () => {
    it("backed_enum_cases_carry_values", () => {
        const nodes = scan({
            "src/lane.ts":
                "export enum Lane { Fast = 'fast', Slow = \"slow\" }\n" +
                "export enum Code { Low = 1, High = -2 }\n",
        });

        expect(nodes.Lane.cases).toEqual([
            { name: "Fast", value: "fast" },
            { name: "Slow", value: "slow" },
        ]);
        expect(nodes.Code.cases).toEqual([
            { name: "High", value: "-2" },
            { name: "Low", value: "1" },
        ]);
        expect(nodes.Lane.fields).toBeUndefined();
    });

    it("numeric_enum_without_initializers_has_no_values", () => {
        const nodes = scan({
            "src/lane.ts":
                "export enum Lane { Fast, Slow }\n" +
                "export enum Mixed { A = 1, B, C = 'c'.length }\n",
        });

        expect(nodes.Lane.cases).toEqual([{ name: "Fast" }, { name: "Slow" }]);
        expect(nodes.Mixed.cases).toEqual([
            { name: "A" },
            { name: "B" },
            { name: "C" },
        ]);
    });

    it("cuts and marks an enum past 64 cases", () => {
        const names = Array.from({ length: 65 }, (_, i) => `C${i}`);
        const nodes = scan({
            "src/big.ts": `export enum Big { ${names.join(", ")} }\n`,
        });

        expect(nodes.Big.cases).toHaveLength(64);
        expect(nodes.Big.cases_truncated).toBe(true);
    });
});
