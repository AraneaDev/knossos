import { describe, expect, it } from "vitest";
import {
    ANSWER_INSTRUCTION,
    extractAnswer,
    matchEntries,
    normalise,
    scoreAnswer,
} from "./answer.mjs";

const block = (body) => `Some prose.\n\n\`\`\`answer\n${body}\n\`\`\`\n`;

describe("extractAnswer", () => {
    it("reads the JSON array in the answer block", () => {
        expect(extractAnswer(block('["a", "b"]'))).toEqual(["a", "b"]);
    });
    it("takes the last block when there are several", () => {
        expect(extractAnswer(block('["old"]') + block('["new"]'))).toEqual([
            "new",
        ]);
    });
    it("returns null for no block, malformed JSON or a non-array", () => {
        expect(extractAnswer("no block")).toBeNull();
        expect(extractAnswer(block('["a",]'))).toBeNull();
        expect(extractAnswer(block('{"a": 1}'))).toBeNull();
        expect(extractAnswer(null)).toBeNull();
    });
});

describe("extractAnswer backslashes", () => {
    it("recovers an unescaped PHP namespace", () => {
        expect(extractAnswer(block('["App\\Billing::summarise"]'))).toEqual([
            "App\\Billing::summarise",
        ]);
    });
    it("does not turn a backslash-n into a newline", () => {
        expect(extractAnswer(block('["App\\name::x"]'))).toEqual([
            "App\\name::x",
        ]);
    });
    it("leaves a correctly escaped block unchanged", () => {
        expect(extractAnswer(block('["App\\\\Billing::x", "a\\"b"]'))).toEqual([
            "App\\Billing::x",
            'a"b',
        ]);
    });
    it("gives null when the block is still invalid after the retry", () => {
        expect(extractAnswer(block('["App\\Billing::x",]'))).toBeNull();
    });
});

describe("normalise", () => {
    const php = { unit: "symbol", language: "php" };
    it("splits PHP symbols into segments, case-insensitively", () => {
        expect(normalise("\\App\\Http\\InvoiceController::show", php)).toBe(
            "app::http::invoicecontroller::show",
        );
        expect(normalise("InvoiceController->show()", php)).toBe(
            "invoicecontroller::show",
        );
    });
    it("keeps case for case-sensitive languages and drops extensions and roots", () => {
        const py = { unit: "symbol", language: "python" };
        expect(normalise("saleor.order.Order.total", py)).toBe(
            "saleor::order::Order::total",
        );
        expect(normalise("src/documents/bulk_edit.py::rotate", py)).toBe(
            "documents::bulk_edit::rotate",
        );
        const rs = { unit: "symbol", language: "rust" };
        expect(normalise("crate::search::Searcher::search_path", rs)).toBe(
            "search::Searcher::search_path",
        );
    });
    it("makes absolute and dotted file paths repo-relative and drops line numbers", () => {
        const file = {
            unit: "file",
            language: "php",
            workspace: "/eval/repos/demo",
        };
        expect(normalise("/eval/repos/demo/app/Billing.php", file)).toBe(
            "app/Billing.php",
        );
        expect(normalise("./app/Billing.php:42", file)).toBe("app/Billing.php");
        expect(normalise("app/Http/", file)).toBe("app/Http");
    });
});

/** Which of `answers` the matcher credits against `expected`, one call per answer. */
const credited = (expected, answers, language) =>
    answers.filter(
        (a) =>
            scoreAnswer(expected, [a], { unit: "symbol", language }).recall > 0,
    );

describe("symbol matching per language", () => {
    it("php: a namespace may be added or dropped down to Class::method", () => {
        const key = ["App\\Helpers\\ImportantDateHelper::getAge"];
        expect(
            credited(
                key,
                [
                    "getAge",
                    "ImportantDateHelper::getAge",
                    "app/Helpers/ImportantDateHelper.php::getAge",
                    "\\App\\Helpers\\ImportantDateHelper::getAge()",
                    "app\\helpers\\importantdatehelper->GETAGE",
                ],
                "php",
            ),
        ).toEqual([
            "ImportantDateHelper::getAge",
            "app/Helpers/ImportantDateHelper.php::getAge",
            "\\App\\Helpers\\ImportantDateHelper::getAge()",
            "app\\helpers\\importantdatehelper->GETAGE",
        ]);
    });
    it("ts: a bare key matches its bare, module, path and qualified forms", () => {
        expect(
            credited(
                ["restoreElements"],
                [
                    "restoreElements",
                    "restore.restoreElements",
                    "packages/excalidraw/data/restore.ts#restoreElements",
                    "excalidraw.data.restore.restoreElements()",
                    "RestoreElements",
                ],
                "typescript",
            ),
        ).toEqual([
            "restoreElements",
            "restore.restoreElements",
            "packages/excalidraw/data/restore.ts#restoreElements",
            "excalidraw.data.restore.restoreElements()",
        ]);
    });
    it("py: a dotted key matches its module and path forms, not its bare name", () => {
        expect(
            credited(
                ["documents.bulk_edit.rotate"],
                [
                    "rotate",
                    "bulk_edit.rotate",
                    "src/documents/bulk_edit.py::rotate",
                    "paperless.documents.bulk_edit.rotate",
                    "documents.bulk_edit.Rotate",
                ],
                "python",
            ),
        ).toEqual([
            "bulk_edit.rotate",
            "src/documents/bulk_edit.py::rotate",
            "paperless.documents.bulk_edit.rotate",
        ]);
    });
    it("rs: a module::function key matches its path and crate forms", () => {
        expect(
            credited(
                ["main::run"],
                [
                    "run",
                    "main::run",
                    "crates/core/main.rs::run",
                    "crate::main::run",
                    "flags::run",
                ],
                "rust",
            ),
        ).toEqual([
            "main::run",
            "crates/core/main.rs::run",
            "crate::main::run",
        ]);
    });
});

describe("symbol pairing", () => {
    const php = { unit: "symbol", language: "php" };
    it("does not let one answer match two keys that share a method name", () => {
        expect(scoreAnswer(["A::data", "B::data"], ["A::data"], php)).toEqual({
            status: "scored",
            precision: 1,
            recall: 0.5,
            f1: 2 / 3,
        });
        expect(
            scoreAnswer(["X\\A::data", "Y\\A::data"], ["A::data"], php).recall,
        ).toBe(0.5);
    });
    it("uses each key once, so two spellings of one key do not both count", () => {
        const s = scoreAnswer(["A::data"], ["X\\A::data", "Y\\A::data"], php);
        expect(s.precision).toBe(0.5);
        expect(s.recall).toBe(1);
    });
    it("pairs the longest overlap first", () => {
        expect(
            matchEntries(
                ["X\\A::data", "A::data"],
                ["A::data", "X\\A::data"],
                php,
            ),
        ).toMatchObject({ hits: 2, missing: [], extra: [] });
    });
    it("matches file units on the normalised path only", () => {
        const file = { unit: "file", language: "php" };
        expect(scoreAnswer(["app/A.php"], ["A.php"], file).f1).toBe(0);
        expect(scoreAnswer(["app/A.php"], ["./app/A.php:3"], file).f1).toBe(1);
    });
});

describe("scoreAnswer", () => {
    const opts = { unit: "symbol", language: "php" };
    it("scores precision, recall and F1", () => {
        const s = scoreAnswer(["A::x", "B::y"], ["A::x", "C::z"], opts);
        expect(s).toEqual({
            status: "scored",
            precision: 0.5,
            recall: 0.5,
            f1: 0.5,
        });
    });
    it("counts duplicates once", () => {
        expect(scoreAnswer(["A::x"], ["A::x", "\\A::x"], opts).precision).toBe(
            1,
        );
    });
    it("gives a missing answer zero and its own status", () => {
        expect(scoreAnswer(["A::x"], null, opts)).toEqual({
            status: "no-answer",
            precision: 0,
            recall: 0,
            f1: 0,
        });
    });
    it("gives an empty answer zero precision rather than credit for silence", () => {
        expect(scoreAnswer(["A::x"], [], opts).f1).toBe(0);
    });
});

describe("ANSWER_INSTRUCTION", () => {
    it("defers to the question's naming and falls back to fully qualified names", () => {
        expect(ANSWER_INSTRUCTION).toContain(
            "Name each entry as the question asks.",
        );
        expect(ANSWER_INSTRUCTION).toMatch(
            /Where the question does not say, name symbols by their fully qualified name/,
        );
        expect(ANSWER_INSTRUCTION).toContain(
            "write each backslash in a name twice",
        );
    });
});
