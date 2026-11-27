<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Fixtures;

use UnexpectedValueException;

/**
 * A reader for the subset of YAML fixtures are written in (Q4(A)): block
 * mappings and sequences (compact forms included), flow `[...]` and `{...}`
 * collections, plain, single- and double-quoted scalars, literal and folded
 * block scalars, and comments. Scalars are {@see YamlScalar}'s (YAML 1.2's
 * core schema, a version-dependent plain scalar ambiguous), flow collections
 * {@see YamlFlow}'s.
 *
 * What it does not read makes the file unparsable, never a guess: an anchor,
 * an alias or a tag (none is ever expanded), a merge key, a complex key, a
 * directive, a second document, a tab in indentation or after a key's
 * colon, a plain or quoted scalar over several lines, and a duplicate key.
 * One leading `---` is a document start and is read past. Nesting deeper than
 * {@see self::MAX_DEPTH} is unparsable, so no file can exhaust the stack.
 */
final class YamlDocument
{
    /** The deepest a collection may nest. */
    public const MAX_DEPTH = YamlFlow::MAX_DEPTH;

    /** @var list<string> */
    private array $lines;

    /** @var array<int, int> line index => the column its content starts at, where a compact form moved it */
    private array $start = [];

    /** The next line to read. */
    private int $at = 0;

    /** Whether the document's start is behind the cursor, so a marker ends it. */
    private bool $body = false;

    private function __construct(string $text)
    {
        $this->lines = explode("\n", str_replace("\r\n", "\n", $text));
        // The break ending the last line starts no line of its own: a kept (`|+`) block at the end
        // of the file would otherwise count one empty line more than the file holds.
        if (count($this->lines) > 1 && end($this->lines) === '') {
            array_pop($this->lines);
        }
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
            $flow = new YamlFlow($text);
            while (!$flow->closed()) {
                $more = $this->next();
                if ($more === null) {
                    throw new UnexpectedValueException('unclosed flow collection');
                }
                $flow->feed(trim(self::uncommented($this->lines[$more])));
                $this->at = $more + 1;
            }

            return $flow->value($index + 1, $depth + 1);
        }
        $value = YamlScalar::of($text, $index + 1);
        $below = $this->next();
        if ($below !== null && $this->column($below) > $this->column($index)) {
            throw new UnexpectedValueException('multi-line scalar');
        }

        return $value;
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
            [$key, $length] = YamlScalar::quoted($text, 0);
            $after = ltrim(substr($text, $length), ' ');

            return $after !== '' && $after[0] === ':' && (strlen($after) === 1 || str_contains(" \t", $after[1])) ? [$key, self::value(substr($after, 1))] : null;
        }
        if (preg_match('/^(.*?):(?:[ \t]|$)/', $text, $match) !== 1) {
            return null;
        }
        $key = rtrim($match[1]);
        if ($key === '<<') {
            throw new UnexpectedValueException('merge key');
        }
        if ($key === '' || str_contains('&*!', $key[0])) {
            throw new UnexpectedValueException('anchor, alias or tag');
        }

        return [$key, self::value(substr($text, strlen($match[1]) + 1))];
    }

    /**
     * What follows a key's colon, trimmed. A tab between them refuses the
     * file: YAML 1.2 reads it as a space, PyYAML refuses it.
     */
    private static function value(string $rest): string
    {
        if (str_contains(substr($rest, 0, strspn($rest, " \t")), "\t")) {
            throw new UnexpectedValueException('tab after a key');
        }

        return trim($rest);
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
                if ($quote === "'" && $character === "'" && ($text[$i + 1] ?? '') === "'") {
                    // `''` is a quote inside a single-quoted scalar, not its end.
                    ++$i;
                    continue;
                }
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
