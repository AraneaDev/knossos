<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Fixtures;

use UnexpectedValueException;

/**
 * One YAML scalar as {@see YamlDocument} and {@see YamlFlow} read it: quoted,
 * or plain and resolved by YAML 1.2's core schema, as Nemesis's `yaml`
 * package resolves it.
 *
 * A plain scalar whose meaning depends on the YAML version a loader follows
 * is {@see Value::AMBIGUOUS}, and no kind is judged against it: `yes`, `no`,
 * `on`, `off`, `y` and `n` in any case (booleans to YAML 1.1, strings to
 * 1.2), digits with `_` (`1_000`), `0b` and `0o` integers, a legacy octal
 * (`010`) and a sexagesimal number (`1:30`).
 */
final class YamlScalar
{
    /** Double-quoted escapes, each with what it stands for. */
    private const ESCAPES = ['0' => "\0", 'a' => "\x07", 'b' => "\x08", 't' => "\t", "\t" => "\t", 'n' => "\n", 'v' => "\x0b", 'f' => "\x0c", 'r' => "\r", 'e' => "\x1b", ' ' => ' ', '"' => '"', '/' => '/', '\\' => '\\', 'N' => "\u{85}", '_' => "\u{a0}", 'L' => "\u{2028}", 'P' => "\u{2029}"];

    /** Plain scalars a YAML 1.1 loader reads as another kind than a YAML 1.2 one. */
    private const AMBIGUOUS = '/^(?:y|n|yes|no|on|off|[-+]?(?:0b[01_]+|0o[0-7_]+|0[0-9_]+|[0-9][0-9_]*_[0-9_]*(?:\.[0-9_]*)?|[0-9]+(?::[0-5]?[0-9])+(?:\.[0-9_]*)?))$/iD';

    private function __construct() {}

    /** One scalar written whole on line `$line`. */
    public static function of(string $text, int $line): Value
    {
        if (str_contains('&*!', $text[0])) {
            throw new UnexpectedValueException('anchor, alias or tag');
        }
        if ($text[0] === '"' || $text[0] === "'") {
            [$scalar, $end] = self::quoted($text, 0);
            if (trim(substr($text, $end)) !== '') {
                throw new UnexpectedValueException('unexpected content');
            }

            return Value::scalar($scalar, $line);
        }
        if (str_contains($text, ': ') || str_starts_with($text, '? ') || str_starts_with($text, '- ') || $text[0] === '@' || $text[0] === '`' || $text[0] === '%') {
            throw new UnexpectedValueException('unsupported plain scalar');
        }
        if (preg_match(self::AMBIGUOUS, $text) === 1) {
            return new Value(Value::AMBIGUOUS, $text, [], $line);
        }

        return Value::scalar(self::resolved($text), $line);
    }

    /**
     * The quoted scalar starting at `$offset` of `$text`, and the offset just
     * past its closing quote; one that does not close there refuses the file.
     *
     * @return array{string, int}
     */
    public static function quoted(string $text, int $offset): array
    {
        $quote = $text[$offset];
        $value = '';
        for ($i = $offset + 1, $length = strlen($text); $i < $length; ++$i) {
            $character = $text[$i];
            if ($quote === "'" && $character === "'") {
                if (($text[$i + 1] ?? '') !== "'") {
                    return [$value, $i + 1];
                }
                $value .= "'";
                ++$i;
            } elseif ($quote === '"' && $character === '"') {
                return [$value, $i + 1];
            } elseif ($quote === '"' && $character === '\\') {
                [$decoded, $taken] = self::escape($text, $i + 1);
                $value .= $decoded;
                $i += $taken;
            } else {
                $value .= $character;
            }
        }

        throw new UnexpectedValueException('multi-line scalar');
    }

    /** A plain scalar's value under YAML 1.2's core schema. */
    private static function resolved(string $text): int|float|string|bool|null
    {
        return match (true) {
            in_array($text, ['~', 'null', 'Null', 'NULL', ''], true) => null,
            in_array($text, ['true', 'True', 'TRUE'], true) => true,
            in_array($text, ['false', 'False', 'FALSE'], true) => false,
            preg_match('/^[-+]?[0-9]+$/D', $text) === 1 => abs((float) $text) < 9.2e18 ? (int) $text : (float) $text,
            preg_match('/^0x[0-9a-fA-F]+$/D', $text) === 1 => (int) hexdec(substr($text, 2)),
            preg_match('/^[-+]?(?:\.[0-9]+|[0-9]+(?:\.[0-9]*)?)(?:[eE][-+]?[0-9]+)?$/D', $text) === 1 => (float) $text,
            preg_match('/^[-+]?\.(?:inf|Inf|INF)$/D', $text) === 1 => $text[0] === '-' ? -INF : INF,
            in_array($text, ['.nan', '.NaN', '.NAN'], true) => NAN,
            default => $text,
        };
    }

    /**
     * The escape after a backslash at `$at`: what it stands for, and how many bytes it took.
     *
     * @return array{string, int}
     */
    private static function escape(string $text, int $at): array
    {
        $code = $text[$at] ?? '';
        if (isset(self::ESCAPES[$code])) {
            return [self::ESCAPES[$code], 1];
        }
        $digits = ['x' => 2, 'u' => 4, 'U' => 8][$code] ?? null;
        $hex = $digits === null ? '' : substr($text, $at + 1, $digits);
        if ($digits === null || strlen($hex) !== $digits || !ctype_xdigit($hex) || ($character = mb_chr((int) hexdec($hex), 'UTF-8')) === false) {
            throw new UnexpectedValueException('invalid escape');
        }

        return [$character, 1 + $digits];
    }
}
