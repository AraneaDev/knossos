<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

/**
 * A URL path as a list of segments, a parameter being null, so a caller's URL
 * and a route's pattern compare whatever framework wrote them.
 */
final readonly class UrlTemplate
{
    private const MAX_VARIANTS = 4;

    /** @param list<?string> $segments */
    private function __construct(public array $segments) {}

    /**
     * The templates a path stands for: none when it is no path (an absolute
     * URL, a regex), two per Laravel optional parameter.
     *
     * @return list<self>
     */
    public static function parse(string $path): array
    {
        $path = trim($path);
        if ($path === '' || str_starts_with($path, '^') || preg_match('~^([a-z][a-z0-9+.-]*:|//)~i', $path) === 1) {
            return [];
        }
        // A `?` closing a Laravel `{slug?}` is not the start of a query string.
        $path = preg_replace('~(\?(?!\})|#).*$~s', '', $path) ?? $path;
        $variants = [[]];
        foreach (explode('/', $path) as $raw) {
            if ($raw === '') {
                continue;
            }
            $optional = preg_match('~^\{[^}]+\?\}$~', $raw) === 1;
            $segment = self::isParameter($raw) ? null : $raw;
            $next = [];
            foreach ($variants as $variant) {
                $next[] = [...$variant, $segment];
                if ($optional && count($next) < self::MAX_VARIANTS) {
                    $next[] = $variant;
                }
            }
            $variants = array_slice($next, 0, self::MAX_VARIANTS);
        }

        return array_map(static fn(array $segments): self => new self($segments), $variants);
    }

    /** How many segments are literal rather than parameters. */
    public function literalCount(): int
    {
        return count(array_filter($this->segments, static fn(?string $segment): bool => $segment !== null));
    }

    /** Whether every segment is a parameter. */
    public function isAllParameters(): bool
    {
        return $this->literalCount() === 0;
    }

    /** Whether both templates have the same literal segments and this one has no parameter. */
    public function equalsExactly(self $route): bool
    {
        return !in_array(null, $this->segments, true) && $this->segments === $route->segments;
    }

    /** Whether the templates have the same length and agree once route parameters match any segment. */
    public function matchesWithParameters(self $route): bool
    {
        return count($this->segments) === count($route->segments) && self::fits($this->segments, $route->segments);
    }

    /** Whether this template ends with the route template on a segment boundary. */
    public function endsWith(self $route): bool
    {
        $length = count($route->segments);

        return $length <= count($this->segments)
            && self::fits(array_slice($this->segments, count($this->segments) - $length), $route->segments);
    }

    /** This template behind the prefix. */
    public function prepend(self $prefix): self
    {
        return new self([...$prefix->segments, ...$this->segments]);
    }

    /** The template as a path, with each parameter written as {}. */
    public function __toString(): string
    {
        return '/' . implode('/', array_map(static fn(?string $segment): string => $segment ?? '{}', $this->segments));
    }

    /** Whether a path segment is written as a parameter. */
    private static function isParameter(string $segment): bool
    {
        return preg_match('~^(\{[^}]*\}|<[^>]+>|:[A-Za-z_]\w*)$~', $segment) === 1;
    }

    /**
     * Whether the caller segments agree with the route segments, position by position.
     *
     * @param list<?string> $caller
     * @param list<?string> $route
     */
    private static function fits(array $caller, array $route): bool
    {
        foreach ($route as $index => $segment) {
            if ($segment === null) {
                continue;
            }
            if ($caller[$index] !== $segment) {
                return false;
            }
        }

        return true;
    }
}
