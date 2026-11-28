<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Fixtures;

use UnexpectedValueException;

/**
 * A position-tracking JSON reader for fixture files: each object member
 * carries its key's line, so a finding points at the key rather than at line
 * 1 as Nemesis's `JSON.parse` leaves it (`staleFixtures.ts:350-354`).
 *
 * Strict RFC 8259, a leading byte-order mark aside. Strings and numbers are
 * decoded by `json_decode` on their own token, so escapes, UTF-8 and number
 * range are PHP's. An object with a duplicate key makes the whole file
 * unparsable (reason `duplicate key`, preflight F25): `JSON.parse` lets the
 * last one win, but which one the author meant is not known. Nesting deeper
 * than {@see self::MAX_DEPTH} is unparsable too, so no file can exhaust the
 * stack.
 */
final class JsonDocument
{
    /** The deepest an array or object may nest. */
    public const MAX_DEPTH = 128;

    /** A string token: no raw control character, only JSON's escapes. */
    private const STRING = '/\G"(?:[^"\\\\\x00-\x1f]++|\\\\(?:["\\\\\/bfnrt]|u[0-9a-fA-F]{4}))*+"/';

    /** A number token. */
    private const NUMBER = '/\G-?(?:0|[1-9][0-9]*+)(\.[0-9]++)?([eE][+-]?[0-9]++)?/';

    private int $at = 0;

    private int $line = 1;

    private function __construct(private readonly string $text) {}

    /**
     * The document `$bytes` hold; null when they are not one JSON value, with
     * why in `$reason` (`invalid JSON`, `duplicate key` or `nested too deep`).
     */
    public static function parse(string $bytes, ?string &$reason = null): ?Document
    {
        $reason = null;
        $reader = new self(str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes);
        try {
            $reader->space();
            $root = $reader->value(0);
            $reader->space();
            if ($reader->at !== strlen($reader->text)) {
                throw new UnexpectedValueException('invalid JSON');
            }
        } catch (UnexpectedValueException $failure) {
            $reason = $failure->getMessage();

            return null;
        }

        return new Document($root);
    }

    /** The value at the cursor, `$depth` arrays or objects in. */
    private function value(int $depth): Value
    {
        $line = $this->line;

        return match ($this->text[$this->at] ?? '') {
            '{' => new Value(Value::DICT, null, $this->members($depth + 1), $line),
            '[' => new Value(Value::LIST, null, $this->items($depth + 1), $line),
            '"' => Value::scalar($this->string(), $line),
            't' => Value::scalar($this->literal('true', true), $line),
            'f' => Value::scalar($this->literal('false', false), $line),
            'n' => Value::scalar($this->literal('null', null), $line),
            default => Value::scalar($this->number(), $line),
        };
    }

    /**
     * An object's members by key, each on its key's line; the cursor is on its `{`.
     *
     * @return array<string, Value>
     */
    private function members(int $depth): array
    {
        $this->deepen($depth);
        ++$this->at;
        $members = [];
        $this->space();
        if ($this->peek() === '}') {
            ++$this->at;

            return $members;
        }
        do {
            $this->space();
            $line = $this->line;
            if ($this->peek() !== '"') {
                throw new UnexpectedValueException('invalid JSON');
            }
            $key = $this->string();
            if (array_key_exists($key, $members)) {
                throw new UnexpectedValueException('duplicate key');
            }
            $this->space();
            $this->expect(':');
            $this->space();
            $members[$key] = $this->value($depth)->at($line);
            $this->space();
        } while ($this->comma());
        $this->expect('}');

        return $members;
    }

    /**
     * An array's items; the cursor is on its `[`.
     *
     * @return list<Value>
     */
    private function items(int $depth): array
    {
        $this->deepen($depth);
        ++$this->at;
        $items = [];
        $this->space();
        if ($this->peek() === ']') {
            ++$this->at;

            return $items;
        }
        do {
            $this->space();
            $items[] = $this->value($depth);
            $this->space();
        } while ($this->comma());
        $this->expect(']');

        return $items;
    }

    /** Refuse to go deeper than the bound. */
    private function deepen(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new UnexpectedValueException('nested too deep');
        }
    }

    /** The string token at the cursor, decoded. */
    private function string(): string
    {
        $decoded = $this->token(self::STRING);

        return is_string($decoded) ? $decoded : throw new UnexpectedValueException('invalid JSON');
    }

    /** The number token at the cursor, an int unless it has a fraction or an exponent or overflows. */
    private function number(): int|float
    {
        $decoded = $this->token(self::NUMBER);

        return is_int($decoded) || is_float($decoded) ? $decoded : throw new UnexpectedValueException('invalid JSON');
    }

    /** The token `$pattern` matches at the cursor, decoded by PHP's JSON decoder; the cursor moves past it. */
    private function token(string $pattern): mixed
    {
        if (preg_match($pattern, $this->text, $match, 0, $this->at) !== 1) {
            throw new UnexpectedValueException('invalid JSON');
        }
        $this->at += strlen($match[0]);
        $fraction = ($match[1] ?? '') !== '' || ($match[2] ?? '') !== '';
        $decoded = json_decode($match[0], false, 2, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new UnexpectedValueException('invalid JSON');
        }
        if ($pattern === self::NUMBER) {
            // A fraction or an exponent makes a float, as Nemesis's lattice reads a written number; so does an int past PHP's range.
            return $fraction || is_string($decoded) ? (float) $match[0] : $decoded;
        }

        return $decoded;
    }

    /** `$word` at the cursor, as `$value`. */
    private function literal(string $word, ?bool $value): ?bool
    {
        if (substr($this->text, $this->at, strlen($word)) !== $word) {
            throw new UnexpectedValueException('invalid JSON');
        }
        $this->at += strlen($word);

        return $value;
    }

    /** Skip whitespace, counting the lines it holds. */
    private function space(): void
    {
        $length = strspn($this->text, " \t\n\r", $this->at);
        $this->line += substr_count($this->text, "\n", $this->at, $length);
        $this->at += $length;
    }

    /** Whether a comma follows, which it consumes. */
    private function comma(): bool
    {
        if ($this->peek() !== ',') {
            return false;
        }
        ++$this->at;

        return true;
    }

    /** Consume `$character`, or refuse the document. */
    private function expect(string $character): void
    {
        if ($this->peek() !== $character) {
            throw new UnexpectedValueException('invalid JSON');
        }
        ++$this->at;
    }

    /** The character at the cursor; empty at the end. */
    private function peek(): string
    {
        return $this->text[$this->at] ?? '';
    }
}
