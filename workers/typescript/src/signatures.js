/**
 * The signature of a function or method as its source writes it: parameter
 * names, the types annotated on them, and the declared return type.
 */

import ts from "typescript";
import { unwrapExpression } from "./typescript-fact-utils.js";

/** Parameters a signature lists at most; the rest are cut and it says so. */
export const MAX_PARAMS = 12;

/** Characters of a type's text a signature keeps at most. */
export const MAX_TYPE_LENGTH = 80;

// Control characters, C1 controls and the Unicode line and paragraph
// separators: a signature is shown to an agent as one line of text.
// eslint-disable-next-line no-control-regex
const UNPRINTABLE = /[\u0000-\u001f\u007f-\u009f\u2028\u2029]/g;

/**
 * A type's source text on one line and within the length kept.
 *
 * @returns {{text: string, cut: boolean}}
 */
function typeText(type, sourceFile) {
    const text = type
        .getText(sourceFile)
        .replace(UNPRINTABLE, " ")
        .replace(/\s+/g, " ")
        .trim();
    return text.length > MAX_TYPE_LENGTH
        ? { text: `${text.slice(0, MAX_TYPE_LENGTH - 1)}…`, cut: true }
        : { text, cut: false };
}

/** The function a declaration declares or holds, if it is one. */
function functionOf(node) {
    if (ts.isFunctionLike(node)) return node;
    const value = unwrapExpression(node.initializer);
    return value !== undefined &&
        (ts.isArrowFunction(value) || ts.isFunctionExpression(value))
        ? value
        : undefined;
}

/** A parameter's name; a destructured one by its shape, since it has none. */
function parameterName(parameter, sourceFile) {
    if (ts.isIdentifier(parameter.name))
        return parameter.name.getText(sourceFile);
    return ts.isObjectBindingPattern(parameter.name) ? "{…}" : "[…]";
}

/**
 * The `signature` attribute of a function or method declaration, or
 * undefined for a declaration that holds no function this can read (a
 * property assigned a wrapped function, such as `debounce(() => {})`).
 *
 * Types are the ones written: an unannotated parameter has no `type` and an
 * undeclared return has no `returns`. Nothing is inferred, so a signature
 * says the same thing whether or not the project's dependencies are
 * installed. `optional` marks a parameter with `?` or a default value,
 * `variadic` a rest parameter. A `this` parameter is not one a caller passes
 * and is left out.
 *
 * @returns {{params: Array<{name: string, type?: string, optional?: true, variadic?: true}>, returns?: string, truncated?: true} | undefined}
 */
export function signatureOf(node, sourceFile) {
    const declared = functionOf(node);
    if (declared === undefined) return undefined;
    let truncated = false;
    const passed = declared.parameters.filter(
        (parameter) =>
            !(
                ts.isIdentifier(parameter.name) &&
                parameter.name.escapedText === "this"
            ),
    );
    if (passed.length > MAX_PARAMS) truncated = true;
    const params = passed.slice(0, MAX_PARAMS).map((parameter) => {
        const entry = { name: parameterName(parameter, sourceFile) };
        if (parameter.type !== undefined) {
            const { text, cut } = typeText(parameter.type, sourceFile);
            entry.type = text;
            truncated ||= cut;
        }
        if (
            parameter.questionToken !== undefined ||
            parameter.initializer !== undefined
        )
            entry.optional = true;
        if (parameter.dotDotDotToken !== undefined) entry.variadic = true;
        return entry;
    });
    const signature = { params };
    if (declared.type !== undefined) {
        const { text, cut } = typeText(declared.type, sourceFile);
        signature.returns = text;
        truncated ||= cut;
    }
    if (truncated) signature.truncated = true;
    return signature;
}
