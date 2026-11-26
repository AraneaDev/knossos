<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Fixtures;

use UnexpectedValueException;

/**
 * A reader for the subset of YAML fixtures are written in (Q4(A)): block
 * mappings and sequences (compact forms included), flow `[...]` and `{...}`
 * collections, plain, single- and double-quoted scalars, literal and folded
 * block scalars, and comments. Plain scalars resolve as YAML 1.2's core
 * schema has them, as Nemesis's `yaml` package does: `yes` is a string.
 *
 * What it does not read makes the file unparsable, never a guess: an anchor,
 * an alias or a tag (none is ever expanded), a merge key, a complex key, a
 * directive, a second document, a tab in indentation, a plain or quoted
 * scalar over several lines, and a duplicate key. One leading `---` is a
 * document start and is read past. Nesting deeper than
 * {@see self::MAX_DEPTH} is unparsable, so no file can exhaust the stack.
 */
final class YamlDocument
{
    /** The deepest a collection may nest. */
    public const MAX_DEPTH = 128;

    /** Double-quoted escapes, each with what it stands for. */
    private const ESCAPES = ['0' => "\0", 'a' => "\x07", 'b' => "\x08", 't' => "\t", "\t" => "\t", 'n' => "\n", 'v' => "\x0b", 'f' => "\x0c", 'r' => "\r", 'e' => "\x1b", ' ' => ' ', '"' => '"', '/' => '/', '\\' => '\\', 'N' => "\u{85}", '_' => "\u{a0}", 'L' => "\u{2028}", 'P' => "\u{2029}"];

    /** @var list<string> */
    private array $lines;

    /** @var array<int, int> line index => the column its content starts at, where a compact form moved it */
    private array $start = [];

    /** The next line to read. */
    private int $at = 0;

    /** Whether the document's start is behind the cursor, so a marker ends it. */
    private bool $body = false;

    /** Flow text being read, and where in it. */
    private string $flow = '';

    private int $flowAt = 0;

    private function __construct(string $text)
    {
        $this->lines = explode("\n", str_replace("\r\n", "\n", $text));
    }

    /**
     * The document `$bytes` hold; null when they leave the subset, with why
     * in `$reason`.
     */
    public static function parse(string $bytes, ?string &$reason = null): ?Document
    {
        $reason = null;
        $reader = new self(str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes);
        try {
            $root = $reader->document();
        } catch (UnexpectedValueException $failure) {
            $reason = $failure->getMessage();

            return null;
        }

        return new Document($root);
    }

    /** The one document: an optional leading `---`, one node, and nothing after it. */
    private function document(): Value
    {
        $first = $this->next();
        if ($first !== null && preg_match('/^---(?:\s+#.*)?\s*$/D', $this->lines[$first]) === 1) {
            $this->at = $first + 1;
        } elseif ($first !== null && preg_match('/^(?:---|\.\.\.)(?:\s|$)/', $this->lines[$first]) === 1) {
            throw new UnexpectedValueException('unsupported document start');
        }
        $this->body = true;
        $root = $this->next() === null ? Value::scalar(null, 1) : $this->node(0, 0);
        if ($this->next() !== null) {
            throw new UnexpectedValueException('unexpected content');
        }

        return $root;
    }

    /**
     * The index of the next line holding content, the cursor moved to it;
     * null at the end. A directive, a document marker past the start or a
     * tab in indentation refuses the file.
     */
    private function next(): ?int
    {
        for ($count = count($this->lines); $this->at < $count; ++$this->at) {
            if (isset($this->start[$this->at])) {
                return $this->at;
            }
            $line = $this->lines[$this->at];
            $trimmed = ltrim($line, " \t");
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            if (strspn($line, ' ') < strlen($line) - strlen($trimmed)) {
                throw new UnexpectedValueException('tab indentation');
            }
            if ($line[0] === '%') {
                throw new UnexpectedValueException('directive');
            }
            if ($this->body && preg_match('/^(?:---|\.\.\.)(?:\s|$)/', $line) === 1) {
                throw new UnexpectedValueException('multiple documents');
            }

            return $this->at;
        }

        return null;
    }

    /** The column a line's content starts at. */
    private function column(int $index): int
    {
        return $this->start[$index] ?? strlen($this->lines[$index]) - strlen(ltrim($this->lines[$index], ' '));
    }

    /** A line's content from its column, its comment cut off. */
    private function text(int $index): string
    {
        return rtrim(self::uncommented(substr($this->lines[$index], $this->column($index))));
    }

    /** The block node starting on the next content line, which sits at column `$indent` or deeper. */
    private function node(int $indent, int $depth): Value
    {
        if ($depth > self::MAX_DEPTH) {
            throw new UnexpectedValueException('nested too deep');
        }
        $index = (int) $this->next();
        $column = $this->column($index);
        $text = $this->text($index);
        if ($column < $indent) {
            throw new UnexpectedValueException('bad indentation');
        }
        if (self::isItem($text)) {
            return $this->sequence($column, $depth);
        }
        if (self::entry($text) !== null) {
            return $this->mapping($column, $depth);
        }
        $this->at = $index + 1;

        return $this->inline($text, $index, $depth);
    }

    /** A block mapping whose keys sit at column `$indent`. */
    private function mapping(int $indent, int $depth): Value
    {
        $members = [];
        $line = $this->at + 1;
        while (($index = $this->next()) !== null && ($column = $this->column($index)) >= $indent) {
            $text = $this->text($index);
            $entry = $column === $indent ? self::entry($text) : null;
            if ($entry === null) {
                throw new UnexpectedValueException($column > $indent ? 'bad indentation' : 'not a mapping entry');
            }
            [$key, $rest] = $entry;
            if (array_key_exists($key, $members)) {
                throw new UnexpectedValueException('duplicate key');
            }
            $this->at = $index + 1;
            $members[$key] = $this->valueOf($rest, $index, $indent, $depth, true)->at($index + 1);
        }

        return new Value(Value::DICT, null, $members, $line);
    }

    /** A block sequence whose dashes sit at column `$indent`. */
    private function sequence(int $indent, int $depth): Value
    {
        $items = [];
        $line = $this->at + 1;
        while (($index = $this->next()) !== null && ($column = $this->column($index)) >= $indent) {
            $text = $this->text($index);
            if ($column > $indent) {
                throw new UnexpectedValueException('bad indentation');
            }
            if (!self::isItem($text)) {
                // A mapping value's sequence at its key's column ends where the next key starts.
                break;
            }
            $rest = ltrim(substr($text, 1), ' ');
            if ($rest !== '' && (self::isItem($rest) || self::entry($rest) !== null)) {
                // A compact form: the item's own block starts after the dash.
                $this->start[$index] = $column + strlen($text) - strlen($rest);
                $items[] = $this->node($indent + 1, $depth + 1)->at($index + 1);
                continue;
            }
            $this->at = $index + 1;
            $items[] = $this->valueOf($rest, $index, $indent, $depth, false)->at($index + 1);
        }

        return new Value(Value::LIST, null, $items, $line);
    }

    /**
     * What follows a key's colon or an item's dash on line `$index`: a block
     * scalar, an inline value, or, when nothing does, the block on the lines
     * below (a mapping value's sequence may sit at the key's own column).
     */
    private function valueOf(string $rest, int $index, int $indent, int $depth, bool $mappingValue): Value
    {
        if ($rest !== '' && ($rest[0] === '|' || $rest[0] === '>')) {
            return $this->block($rest, $index, $indent);
        }
        if ($rest !== '') {
            return $this->inline($rest, $index, $depth + 1);
        }
        $below = $this->next();
        if ($below !== null && ($this->column($below) > $indent || ($mappingValue && $this->column($below) === $indent && self::isItem($this->text($below))))) {
            return $this->node($this->column($below) > $indent ? $indent + 1 : $indent, $depth + 1);
        }

        return Value::scalar(null, $index + 1);
    }

    /** A scalar or flow collection written on line `$index` (a flow one may run on below). */
    private function inline(string $text, int $index, int $depth): Value
    {
        if ($text[0] === '[' || $text[0] === '{') {
            $this->flow = $text;
            while (!self::balanced($this->flow)) {
                $more = $this->next();
                if ($more === null) {
                    throw new UnexpectedValueException('unclosed flow collection');
                }
                $this->flow .= ' ' . trim(self::uncommented($this->lines[$more]));
                $this->at = $more + 1;
            }
            $this->flowAt = 0;
            $value = $this->flowValue($index + 1, $depth + 1);
            $this->flowSpace();
            if ($this->flowAt !== strlen($this->flow)) {
                throw new UnexpectedValueException('unexpected content');
            }

            return $value;
        }
        $value = self::scalarOf($text, $index + 1);
        $below = $this->next();
        if ($below !== null && $this->column($below) > $this->column($index)) {
            throw new UnexpectedValueException('multi-line scalar');
        }

        return $value;
    }

    /** One scalar written whole: quoted, or plain and resolved by the core schema. */
    private static function scalarOf(string $text, int $line): Value
    {
        if (str_contains('&*!', $text[0])) {
            throw new UnexpectedValueException('anchor, alias or tag');
        }
        if ($text[0] === '"' || $text[0] === "'") {
            [$scalar, $length] = self::quoted($text);
            if (trim(substr($text, $length)) !== '') {
                throw new UnexpectedValueException('unexpected content');
            }

            return Value::scalar($scalar, $line);
        }
        if (str_contains($text, ': ') || str_starts_with($text, '? ') || str_starts_with($text, '- ') || $text[0] === '@' || $text[0] === '`' || $text[0] === '%') {
            throw new UnexpectedValueException('unsupported plain scalar');
        }

        return Value::scalar(self::resolved($text), $line);
    }

    /** A plain scalar's value under YAML 1.2's core schema. */
    private static function resolved(string $text): int|float|string|bool|null
    {
        return match (true) {
            in_array($text, ['~', 'null', 'Null', 'NULL', ''], true) => null,
            in_array($text, ['true', 'True', 'TRUE'], true) => true,
            in_array($text, ['false', 'False', 'FALSE'], true) => false,
            preg_match('/^[-+]?[0-9]+$/D', $text) === 1 => abs((float) $text) < 9.2e18 ? (int) $text : (float) $text,
            preg_match('/^0o[0-7]+$/D', $text) === 1 => (int) octdec(substr($text, 2)),
            preg_match('/^0x[0-9a-fA-F]+$/D', $text) === 1 => (int) hexdec(substr($text, 2)),
            preg_match('/^[-+]?(?:\.[0-9]+|[0-9]+(?:\.[0-9]*)?)(?:[eE][-+]?[0-9]+)?$/D', $text) === 1 => (float) $text,
            preg_match('/^[-+]?\.(?:inf|Inf|INF)$/D', $text) === 1 => $text[0] === '-' ? -INF : INF,
            in_array($text, ['.nan', '.NaN', '.NAN'], true) => NAN,
            default => $text,
        };
    }

    /**
     * A quoted scalar at the start of `$text`, and how many bytes it took.
     *
     * @return array{string, int}
     */
    private static function quoted(string $text): array
    {
        $quote = $text[0];
        $value = '';
        for ($i = 1, $length = strlen($text); $i < $length; ++$i) {
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

    /** A literal (`|`) or folded (`>`) block scalar whose header is on line `$index`, under a node at column `$indent`. */
    private function block(string $header, int $index, int $indent): Value
    {
        if (preg_match('/^([|>])([-+]?)([1-9]?)([-+]?)$/D', $header, $match) !== 1 || ($match[2] !== '' && $match[4] !== '')) {
            throw new UnexpectedValueException('unsupported block scalar');
        }
        $chomp = $match[2] . $match[4];
        $content = $match[3] === '' ? null : $indent + (int) $match[3];
        $body = [];
        for ($count = count($this->lines), $i = $index + 1; $i < $count; ++$i) {
            $line = $this->lines[$i];
            $width = strlen($line) - strlen(ltrim($line, ' '));
            if (trim($line, ' ') === '') {
                $body[] = '';
                continue;
            }
            $content ??= $width;
            if ($width < $content || $width <= $indent) {
                break;
            }
            $body[] = substr($line, $content);
        }
        $this->at = $index + 1 + count($body);
        $trailing = 0;
        while ($body !== [] && end($body) === '') {
            array_pop($body);
            ++$trailing;
        }
        $text = $match[1] === '|' ? implode("\n", $body) : self::folded($body);
        $text .= match ($chomp) {
            '-' => '',
            '+' => str_repeat("\n", $trailing + ($body === [] ? 0 : 1)),
            default => $body === [] ? '' : "\n",
        };

        return Value::scalar($text, $index + 1);
    }

    /**
     * Folded lines: a line break between two lines of text becomes a space,
     * each empty line a newline, and a more-indented line keeps its breaks.
     *
     * @param list<string> $lines
     */
    private static function folded(array $lines): string
    {
        $text = '';
        $empty = 0;
        $started = false;
        $indented = false;
        foreach ($lines as $line) {
            if ($line === '') {
                ++$empty;
                continue;
            }
            $more = $line[0] === ' ';
            $text .= match (true) {
                !$started || $empty > 0 => str_repeat("\n", $empty),
                $more || $indented => "\n",
                default => ' ',
            };
            $text .= $line;
            [$started, $indented, $empty] = [true, $more, 0];
        }

        return $text;
    }

    /** The flow value at the flow cursor, on line `$line`. */
    private function flowValue(int $line, int $depth): Value
    {
        $this->flowSpace();
        $character = $this->flow[$this->flowAt] ?? '';
        if ($character !== '[' && $character !== '{') {
            $text = $this->flowScalar();

            return $text === '' ? throw new UnexpectedValueException('unexpected content') : self::scalarOf($text, $line);
        }
        if ($depth > self::MAX_DEPTH) {
            throw new UnexpectedValueException('nested too deep');
        }
        ++$this->flowAt;
        $close = $character === '[' ? ']' : '}';
        $children = [];
        while (true) {
            $this->flowSpace();
            if (($this->flow[$this->flowAt] ?? '') === $close) {
                ++$this->flowAt;

                return new Value($close === ']' ? Value::LIST : Value::DICT, null, $children, $line);
            }
            if ($close === ']') {
                $children[] = $this->flowValue($line, $depth + 1);
            } else {
                $key = $this->flowKey();
                if (array_key_exists($key, $children)) {
                    throw new UnexpectedValueException('duplicate key');
                }
                $this->flowSpace();
                $children[$key] = ($this->flow[$this->flowAt] ?? '') === ':' ? $this->flowMember($line, $depth) : Value::scalar(null, $line);
            }
            $this->flowSpace();
            $next = $this->flow[$this->flowAt] ?? '';
            if ($next === ',') {
                ++$this->flowAt;
            } elseif ($next !== $close) {
                throw new UnexpectedValueException('unclosed flow collection');
            }
        }
    }

    /** A flow mapping's value after its colon. */
    private function flowMember(int $line, int $depth): Value
    {
        ++$this->flowAt;
        $this->flowSpace();
        $next = $this->flow[$this->flowAt] ?? '';

        return $next === ',' || $next === '}' ? Value::scalar(null, $line) : $this->flowValue($line, $depth + 1);
    }

    /** A flow mapping's key: quoted, or plain up to its colon. */
    private function flowKey(): string
    {
        $text = $this->flowScalar(true);
        if ($text === '' || $text === '<<' || str_contains('&*!?', $text[0])) {
            throw new UnexpectedValueException($text === '<<' ? 'merge key' : 'anchor, alias or tag');
        }

        return $text[0] === '"' || $text[0] === "'" ? self::quoted($text)[0] : $text;
    }

    /** The text of one flow scalar at the flow cursor, which moves past it. */
    private function flowScalar(bool $key = false): string
    {
        $rest = substr($this->flow, $this->flowAt);
        if ($rest !== '' && ($rest[0] === '"' || $rest[0] === "'")) {
            $length = self::quoted($rest)[1];
        } else {
            $length = preg_match($key ? '/^[^,\[\]{}]*?(?=:(?:\s|[,\[\]{}]|$)|[,\]}]|$)/' : '/^[^,\[\]{}]*/', $rest, $match) === 1 ? strlen($match[0]) : 0;
        }
        $this->flowAt += $length;

        return trim(substr($rest, 0, $length));
    }

    /** Skip spaces in flow text. */
    private function flowSpace(): void
    {
        $this->flowAt += strspn($this->flow, ' ', $this->flowAt);
    }

    /** Whether a flow collection's brackets close, outside quotes. */
    private static function balanced(string $text): bool
    {
        $depth = 0;
        $quote = null;
        for ($i = 0, $length = strlen($text); $i < $length; ++$i) {
            $character = $text[$i];
            if ($quote !== null) {
                $quote = $character === $quote && ($quote === "'" || $text[$i - 1] !== '\\') ? null : $quote;
            } elseif (($character === '"' || $character === "'") && ($i === 0 || str_contains(' [{,:', $text[$i - 1]))) {
                $quote = $character;
            } else {
                $depth += match ($character) {
                    '[', '{' => 1,
                    ']', '}' => -1,
                    default => 0,
                };
            }
        }

        return $depth <= 0;
    }

    /**
     * A mapping entry's key and what follows its colon; null when `$text` is
     * no entry. A merge key, a complex key and an anchored, aliased or tagged
     * key refuse the file.
     *
     * @return array{string, string}|null
     */
    private static function entry(string $text): ?array
    {
        if ($text === '' || $text[0] === '[' || $text[0] === '{') {
            return null;
        }
        if ($text === '?' || str_starts_with($text, '? ')) {
            throw new UnexpectedValueException('complex key');
        }
        if ($text[0] === '"' || $text[0] === "'") {
            [$key, $length] = self::quoted($text);
            $after = ltrim(substr($text, $length), ' ');

            return $after !== '' && $after[0] === ':' && (strlen($after) === 1 || $after[1] === ' ') ? [$key, trim(substr($after, 1))] : null;
        }
        if (preg_match('/^(.*?):(?: |$)/', $text, $match) !== 1) {
            return null;
        }
        $key = rtrim($match[1]);
        if ($key === '<<') {
            throw new UnexpectedValueException('merge key');
        }
        if ($key === '' || str_contains('&*!', $key[0])) {
            throw new UnexpectedValueException('anchor, alias or tag');
        }

        return [$key, trim(substr($text, strlen($match[0])))];
    }

    /** Whether a line's content is a sequence item. */
    private static function isItem(string $text): bool
    {
        return $text === '-' || str_starts_with($text, '- ');
    }

    /** `$text` without its comment: a `#` at the start or after a space, outside quotes. */
    private static function uncommented(string $text): string
    {
        $quote = null;
        for ($i = 0, $length = strlen($text); $i < $length; ++$i) {
            $character = $text[$i];
            if ($quote !== null) {
                $quote = $character === $quote && ($quote === "'" || $text[$i - 1] !== '\\') ? null : $quote;
            } elseif (($character === '"' || $character === "'") && ($i === 0 || str_contains(' [{,:-', $text[$i - 1]))) {
                $quote = $character;
            } elseif ($character === '#' && ($i === 0 || $text[$i - 1] === ' ')) {
                return substr($text, 0, $i);
            }
        }

        return $text;
    }
}
