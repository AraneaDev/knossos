import { describe, expect, it } from "vitest";
import { extractAnswer, normalise, scoreAnswer } from "./answer.mjs";

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

describe("normalise", () => {
    const php = { unit: "symbol", language: "php" };
    it("matches PHP symbols on class and method, case-insensitively", () => {
        expect(normalise("\\App\\Http\\InvoiceController::show", php)).toBe(
            "invoicecontroller::show",
        );
        expect(normalise("InvoiceController->show()", php)).toBe(
            "invoicecontroller::show",
        );
        expect(normalise("app\\http\\invoicecontroller::Show", php)).toBe(
            "invoicecontroller::show",
        );
    });
    it("keeps case for case-sensitive languages and unifies separators", () => {
        const py = { unit: "symbol", language: "python" };
        expect(normalise("saleor.order.Order.total", py)).toBe("Order::total");
        expect(normalise("Order#total", py)).toBe("Order::total");
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
