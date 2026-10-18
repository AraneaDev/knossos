<?php

declare(strict_types=1);

/**
 * Shared checks for the numbers the quality floors and budgets compare against.
 *
 * A floor read from a missing key, a quoted string or a value outside its range
 * does not fail when it should. PHP casts a missing key to 0 and a string to
 * whatever it starts with, and c8 hands a missing key to its comparison as NaN,
 * which no figure is ever below. Each of those reports a pass that compared
 * nothing. Every budget file is checked through here before any figure is held
 * to it, so a budget that cannot act is a refusal that names its key.
 */

/**
 * Read a budget file, or say why it cannot be read.
 *
 * @return array{0: array<mixed>|null, 1: list<string>} the decoded object, or null with the reasons
 */
function readBudgetFile(string $path): array
{
    if (!is_file($path)) {
        return [null, [sprintf('the budget file %s does not exist.', $path)]];
    }
    try {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return [null, [sprintf('the budget file %s is not valid JSON: %s.', $path, $exception->getMessage())]];
    }
    if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
        return [null, [sprintf('the budget file %s must hold a JSON object.', $path)]];
    }

    return [$decoded, []];
}

/**
 * The value at a dotted key such as `typescript.lines`, or null when any step is missing.
 *
 * @param array<mixed> $budgets
 */
function budgetAt(array $budgets, string $key): mixed
{
    $value = $budgets;
    foreach (explode('.', $key) as $step) {
        if (!is_array($value) || !array_key_exists($step, $value)) {
            return null;
        }
        $value = $value[$step];
    }

    return $value;
}

/**
 * Why `$value`, read from the budget key `$key`, cannot serve as a limit, or null when it can.
 *
 * Only a JSON number counts. A string such as "95" is refused rather than cast,
 * so a quoted value is never quietly read as another number.
 */
function budgetNumberError(string $key, mixed $value, float $minimum, ?float $maximum = null): ?string
{
    if ($value === null) {
        return sprintf('%s is missing.', $key);
    }
    if (!is_int($value) && !is_float($value)) {
        return sprintf('%s is %s; it must be a number.', $key, json_encode($value, JSON_UNESCAPED_SLASHES) ?: get_debug_type($value));
    }
    if (!is_finite((float) $value)) {
        return sprintf('%s is not a finite number.', $key);
    }
    if ($value < $minimum || ($maximum !== null && $value > $maximum)) {
        return $maximum === null
            ? sprintf('%s is %s; it must be at least %s.', $key, (string) $value, (string) $minimum)
            : sprintf('%s is %s; it must lie between %s and %s.', $key, (string) $value, (string) $minimum, (string) $maximum);
    }

    return null;
}
