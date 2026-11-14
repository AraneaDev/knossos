<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * A component's stored signature as the one line a reader calls it by:
 * `scan(root: string, limit?: number, ...rest: string[]): Promise<void>`.
 *
 * The line is language-neutral on purpose. It says what to pass and what
 * comes back, in one form for every worker; the structured `signature`
 * attribute beside it keeps the parts.
 */
final class SignatureLine
{
    /** Parameters a worker lists at most; a truncated signature holding that many lost some. */
    private const LISTED = 12;

    private function __construct() {}

    /**
     * A component's `attributes`, with a stored signature taken out of them
     * and given as the `signature` line instead.
     *
     * The parts stay in the graph for a query to use. An answer carries the
     * line alone: it says the same in a fraction of the space, and the space
     * is an agent's context.
     *
     * @param array<string, mixed> $attributes
     * @return array{attributes: array<string, mixed>, signature?: string}
     */
    public static function fields(array $attributes, string $displayName): array
    {
        $line = self::of($attributes['signature'] ?? null, $displayName);
        if ($line === null) {
            return ['attributes' => $attributes];
        }
        unset($attributes['signature']);

        return ['attributes' => $attributes, 'signature' => $line];
    }

    /**
     * The line for one stored signature, or null for anything that is not one.
     *
     * A parameter a worker cut from the list shows as `…`. A name that
     * already carries its stars (`*args`) is not marked variadic twice.
     */
    public static function of(mixed $signature, string $displayName): ?string
    {
        if (!is_array($signature) || !is_array($signature['params'] ?? null)) {
            return null;
        }
        $params = [];
        foreach ($signature['params'] as $param) {
            if (!is_array($param) || !is_string($param['name'] ?? null)) {
                return null;
            }
            $name = $param['name'];
            $text = (($param['variadic'] ?? false) === true && !str_starts_with($name, '*') ? '...' : '') . $name
                . (($param['optional'] ?? false) === true ? '?' : '');
            $params[] = is_string($param['type'] ?? null) ? $text . ': ' . $param['type'] : $text;
        }
        if (($signature['truncated'] ?? false) === true && count($params) >= self::LISTED) {
            $params[] = '…';
        }
        $returns = is_string($signature['returns'] ?? null) ? ': ' . $signature['returns'] : '';

        return self::clean(sprintf('%s(%s)%s', $displayName, implode(', ', $params), $returns));
    }

    /** One line, whatever a worker or an imported graph put in the parts. */
    private static function clean(string $line): string
    {
        return trim((string) preg_replace('/[\x00-\x1f\x7f\x{0080}-\x{009f}\x{2028}\x{2029}]+/u', ' ', $line));
    }
}
