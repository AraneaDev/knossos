import { describe, expect, it } from "vitest";

import { doubleAt, fixture, scan } from "./support/test-case-facts.mjs";

// A test says how many arguments a double's member was called with, as in
// `expect(spy).toHaveBeenCalledWith(a, b)`. The member's fact carries the
// count, for the core to hold against what the member takes.

const SERVICE =
    "export class Svc { run(a: string, b: number): void {} stop(): void {} }\n" +
    "export interface Port { send(a: string): void }\n";

const HEAD = [
    "import { expect, it, vi } from 'vitest';",
    "import { Svc, type Port } from '../src/svc';",
];

function inTest(...body) {
    return {
        "src/svc.ts": SERVICE,
        "tests/svc.test.ts": [
            ...HEAD,
            "it('calls', () => {",
            ...body,
            "});",
            "",
        ].join("\n"),
    };
}

const PATH = "tests/svc.test.ts";

describe("the arguments a call matcher expects", () => {
    it("records the arguments a call matcher expects", () => {
        const { nodes } = scan(fixture("nemesis"));
        const spy = doubleAt(nodes, "tests/UserService.spec.ts", 29);

        // The matcher is called on `(spy as any)`, not on `expect(spy)`.
        expect(spy.attributes.members[0].called_with).toEqual([
            {
                api: "toHaveBeenCalledWith",
                line: 31,
                count: 3,
                spread: false,
            },
        ]);
    });

    it("counts every argument, or every one after the call number", () => {
        const { nodes } = scan(
            inTest(
                "  const svc = new Svc();",
                "  const spy = vi.spyOn(svc, 'run');",
                "  expect(spy).toHaveBeenCalledWith('a', 1);",
                "  expect(spy).toBeCalledWith('a');",
                "  expect(spy).not.toHaveBeenLastCalledWith('a', 1, 2);",
                "  expect(spy).lastCalledWith();",
                "  expect(spy).toHaveBeenNthCalledWith(2, 'a', 1);",
                "  expect(spy).nthCalledWith(1);",
                "  expect(spy).toHaveBeenCalledTimes(1);",
            ),
        );
        const calls = doubleAt(nodes, PATH, 5).attributes.members[0]
            .called_with;

        expect(calls.map(({ api, line, count }) => [api, line, count])).toEqual(
            [
                ["toHaveBeenCalledWith", 6, 2],
                ["toBeCalledWith", 7, 1],
                ["toHaveBeenLastCalledWith", 8, 3],
                ["lastCalledWith", 9, 0],
                ["toHaveBeenNthCalledWith", 10, 2],
                ["nthCalledWith", 11, 0],
            ],
        );
    });

    it("marks a spread matcher", () => {
        const { nodes } = scan(
            inTest(
                "  const args = ['a'];",
                "  const spy = vi.spyOn(new Svc(), 'run');",
                "  expect(spy).toHaveBeenCalledWith(...args);",
                "  expect(spy).toHaveBeenNthCalledWith(...[1, 'a']);",
            ),
        );
        const calls = doubleAt(nodes, PATH, 5).attributes.members[0]
            .called_with;

        expect(calls).toMatchObject([
            { count: 1, spread: true },
            { count: 0, spread: true },
        ]);
    });
});

describe("the links a call matcher is read through", () => {
    // G3-02: a matcher under `.not` says so, as a count no call can meet
    // makes it pass whatever the member is called with; `.resolves` and
    // `.rejects` are read past alike.
    it("marks a matcher read through .not, and reads one through .resolves or .rejects", () => {
        const { nodes } = scan(
            inTest(
                "  const spy = vi.spyOn(new Svc(), 'run');",
                "  expect(spy).not.toHaveBeenCalledWith();",
                "  expect(spy).toHaveBeenCalledWith('a', 1);",
                "  expect(spy).resolves.not.toHaveBeenCalledWith('a');",
                "  expect(spy).rejects.toHaveBeenCalledWith('a', 1, 2);",
                "  expect(spy).not.toHaveBeenNthCalledWith(1);",
            ),
        );

        expect(
            doubleAt(nodes, PATH, 4).attributes.members[0].called_with,
        ).toEqual([
            {
                api: "toHaveBeenCalledWith",
                line: 5,
                count: 0,
                spread: false,
                negated: true,
            },
            { api: "toHaveBeenCalledWith", line: 6, count: 2, spread: false },
            {
                api: "toHaveBeenCalledWith",
                line: 7,
                count: 1,
                spread: false,
                negated: true,
            },
            { api: "toHaveBeenCalledWith", line: 8, count: 3, spread: false },
            {
                api: "toHaveBeenNthCalledWith",
                line: 9,
                count: 0,
                spread: false,
                negated: true,
            },
        ]);
    });

    it("leaves a matcher on a spy that replaces no one named member", () => {
        const { nodes } = scan(
            inTest(
                "  const name = 'run' as const;",
                "  const spy = vi.spyOn(new Svc(), name);",
                "  expect(spy).toHaveBeenCalledWith('a', 1);",
            ),
        );
        const spy = doubleAt(nodes, PATH, 5);

        expect(spy.attributes.members).toEqual([]);
    });
});

describe("the member a call matcher is about", () => {
    it("finds the member a matcher is about through the object that holds it", () => {
        const { nodes } = scan(
            inTest(
                "  const svc = new Svc();",
                "  vi.spyOn(svc, 'run');",
                "  const port = { send: vi.fn() } as unknown as Port;",
                "  const mocked = vi.mocked(new Svc());",
                "  mocked.run.mockReturnValue(undefined);",
                "  expect(svc.run).toHaveBeenCalledWith('x', 2);",
                "  expect(port.send).toHaveBeenCalledWith('a', 'b');",
                "  expect(mocked.run).toHaveBeenCalledWith('a');",
            ),
        );

        expect(
            doubleAt(nodes, PATH, 5).attributes.members[0].called_with,
        ).toMatchObject([{ line: 9, count: 2 }]);
        expect(
            doubleAt(nodes, PATH, 6, "object-literal").attributes.members[0]
                .called_with,
        ).toMatchObject([{ line: 10, count: 2 }]);
        expect(
            doubleAt(nodes, PATH, 7, "vi.mocked-instance").attributes.members[0]
                .called_with,
        ).toMatchObject([{ line: 11, count: 1 }]);
    });

    it("leaves a matcher on something that is no member of a double", () => {
        const { nodes } = scan(
            inTest(
                "  const svc = new Svc();",
                "  const spy = vi.spyOn(svc, 'run');",
                "  const other = new Svc();",
                "  const unknown = { thing: () => {} };",
                "  expect(other.run).toHaveBeenCalledWith('a', 1);",
                "  expect(svc.stop).toHaveBeenCalledWith();",
                "  expect(unknown.thing).toHaveBeenCalledWith('a');",
                "  expect(vi.fn()).toHaveBeenCalledWith('a');",
                "  expect(spy.name).toHaveBeenCalledWith('a');",
                "  expect(svc).toHaveBeenCalledWith('a');",
                "  expect(spy).toHaveBeenCalledWith();",
                "  spy.mockReturnValue(undefined);",
                "  expect(spy.mock.calls).toHaveLength(1);",
            ),
        );
        const [matcher, ...rest] = doubleAt(nodes, PATH, 5).attributes
            .members[0].called_with;

        // Only `expect(spy).toHaveBeenCalledWith()` is about the member.
        expect(rest).toEqual([]);
        expect(matcher).toMatchObject({ line: 14, count: 0 });
    });

    it("states no matcher on a member that has none", () => {
        const { nodes } = scan(
            inTest(
                "  const spy = vi.spyOn(new Svc(), 'run');",
                "  expect(spy).toHaveBeenCalledTimes(0);",
            ),
        );

        expect(
            doubleAt(nodes, PATH, 4).attributes.members[0],
        ).not.toHaveProperty("called_with");
    });
});
