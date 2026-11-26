<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Fixtures;

use UnexpectedValueException;

/**
 * A YAML flow collection (`[...]` or `{...}`) as {@see YamlDocument} reads
 * it, over as many lines as it runs.
 *
 * Linear in its length: each line fed is scanned once for the brackets that
 * open and close outside quotes, and the parse reads every scalar where it
 * stands, never a copy of the text after it. A flow collection over many
 * lines used to rescan all of the text read so far after each line.
 */
final class YamlFlow
{
    /** The deepest a collection may nest. */
    public const MAX_DEPTH = 128;

    private string $text = '';

    private int $depth = 0;

    /** The quote a scalar is open under, carried from one line to the next. */
    private ?string $quote = null;

    /** The character before the next one fed. */
    private string $previous = '';

    private int $at = 0;

    public function __construct(string $first)
    {
        $this->feed($first);
    }

    /** Add one more line's text, joined to the last by a space. */
    public function feed(string $segment): void
    {
        $start = strlen($this->text);
        $this->text .= ($start === 0 ? '' : ' ') . $segment;
        for ($i = $start, $length = strlen($this->text); $i < $length; ++$i) {
            $character = $this->text[$i];
            if ($this->quote !== null) {
                $this->quote = $character === $this->quote && ($this->quote === "'" || $this->previous !== '\\') ? null : $this->quote;
            } elseif (($character === '"' || $character === "'") && ($this->previous === '' || str_contains(' [{,:', $this->previous))) {
                $this->quote = $character;
            } elseif ($character === '[' || $character === '{') {
                ++$this->depth;
            } elseif ($character === ']' || $character === '}') {
                --$this->depth;
            }
            $this->previous = $character;
        }
    }

    /** Whether every bracket opened has closed. */
    public function closed(): bool
    {
        return $this->depth <= 0;
    }

    /** The collection, on line `$line`, `$depth` collections in; anything after it refuses the file. */
    public function value(int $line, int $depth): Value
    {
        $this->at = 0;
        $value = $this->node($line, $depth);
        $this->space();
        if ($this->at !== strlen($this->text)) {
            throw new UnexpectedValueException('unexpected content');
        }

        return $value;
    }

    /** The flow node at the cursor. */
    private function node(int $line, int $depth): Value
    {
        $this->space();
        $character = $this->text[$this->at] ?? '';
        if ($character !== '[' && $character !== '{') {
            $text = $this->scalar(false);

            return $text === '' ? throw new UnexpectedValueException('unexpected content') : YamlScalar::of($text, $line);
        }
        if ($depth > self::MAX_DEPTH) {
            throw new UnexpectedValueException('nested too deep');
        }
        ++$this->at;
        $close = $character === '[' ? ']' : '}';
        $children = [];
        while (true) {
            $this->space();
            if (($this->text[$this->at] ?? '') === $close) {
                ++$this->at;

                return new Value($close === ']' ? Value::LIST : Value::DICT, null, $children, $line);
            }
            if ($close === ']') {
                $children[] = $this->node($line, $depth + 1);
            } else {
                $key = $this->key();
                if (array_key_exists($key, $children)) {
                    throw new UnexpectedValueException('duplicate key');
                }
                $this->space();
                $children[$key] = ($this->text[$this->at] ?? '') === ':' ? $this->member($line, $depth) : Value::scalar(null, $line);
            }
            $this->space();
            $next = $this->text[$this->at] ?? '';
            if ($next === ',') {
                ++$this->at;
            } elseif ($next !== $close) {
                throw new UnexpectedValueException('unclosed flow collection');
            }
        }
    }

    /** A flow mapping's value after its colon. */
    private function member(int $line, int $depth): Value
    {
        ++$this->at;
        $this->space();
        $next = $this->text[$this->at] ?? '';

        return $next === ',' || $next === '}' ? Value::scalar(null, $line) : $this->node($line, $depth + 1);
    }

    /** A flow mapping's key: quoted, or plain up to its colon. */
    private function key(): string
    {
        $text = $this->scalar(true);
        if ($text === '' || $text === '<<' || str_contains('&*!?', $text[0])) {
            throw new UnexpectedValueException($text === '<<' ? 'merge key' : 'anchor, alias or tag');
        }

        return $text[0] === '"' || $text[0] === "'" ? YamlScalar::quoted($text, 0)[0] : $text;
    }

    /** The text of one flow scalar at the cursor, which moves past it. */
    private function scalar(bool $key): string
    {
        $start = $this->at;
        $first = $this->text[$start] ?? '';
        if ($first === '"' || $first === "'") {
            $this->at = YamlScalar::quoted($this->text, $start)[1];
        } else {
            $pattern = $key ? '/\G[^,\[\]{}]*?(?=:(?:\s|[,\[\]{}]|$)|[,\]}]|$)/' : '/\G[^,\[\]{}]*/';
            $this->at += preg_match($pattern, $this->text, $match, 0, $start) === 1 ? strlen($match[0]) : 0;
        }

        return trim(substr($this->text, $start, $this->at - $start));
    }

    /** Skip spaces. */
    private function space(): void
    {
        $this->at += strspn($this->text, ' ', $this->at);
    }
}
