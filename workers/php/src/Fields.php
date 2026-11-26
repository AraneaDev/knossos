<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/**
 * The fields a class, interface or trait declares itself, and the cases an
 * enum declares, for the test-fidelity fixture checks.
 *
 * Fields are the instance properties and the promoted constructor
 * properties. A field is required unless it has a default: an untyped
 * property defaults to null, and a nullable type alone does not make the key
 * optional. Inherited fields stay with the parent; the core composes them
 * along the inheritance edges, and a parent outside the scan leaves the whole
 * list unknown there. The list is marked `fields_open` when a serializer may
 * read a key by another name: an attribute or docblock tag from Symfony
 * Serializer, JMS Serializer or Spatie Data on the class or a field.
 * `__get` and `__set` leave it closed; the fixture check reads them as
 * methods of their own. A list longer than `MAX_ENTRIES` is cut and marked.
 */
final class Fields
{
    /** Fields or cases listed at most; a longer list is cut and marked truncated. */
    public const MAX_ENTRIES = 64;

    /** The namespaces of the serializers whose attributes and tags may rename a key. */
    private const SERIALIZER_NAMESPACES = ['Symfony\\Component\\Serializer\\', 'JMS\\Serializer\\', 'Spatie\\LaravelData\\'];

    /** The renaming attribute and tag names, whatever namespace or alias they are written under. */
    private const RENAMING_NAMES = ['SerializedName', 'SerializedPath', 'Groups', 'MapInputName', 'MapName', 'MapOutputName'];

    /**
     * A docblock tag of a serializer: a renaming name, Symfony's `@Ignore`
     * and `@MaxDepth` (a key left off or cut short), or any tag under a JMS
     * or Serializer alias.
     */
    private const SERIALIZER_TAG = '/(?<=[\s*])@(?:(?:JMS|Serializer)\\\\[A-Za-z]|(?:[A-Za-z_\\\\]+\\\\)?(?:SerializedName|SerializedPath|Groups|MapInputName|MapName|MapOutputName|Ignore|MaxDepth)(?![\w\\\\]))/';

    private function __construct() {}

    /**
     * The `fields` or `cases` attributes of a class-like node.
     *
     * @return array<string, mixed>
     */
    public static function of(Stmt\ClassLike $node): array
    {
        if ($node instanceof Stmt\Enum_) {
            return self::cases($node);
        }
        $fields = [];
        $open = self::renames($node->attrGroups, $node);
        foreach ($node->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $open = $open || self::renames($property->attrGroups, $property);
            foreach ($property->props as $item) {
                $fields[$item->name->toString()] = self::field($item->name->toString(), $property->type, $item->default !== null || $property->type === null, $item->getStartLine());
            }
        }
        foreach ($node->getMethod('__construct')->params ?? [] as $param) {
            if ($param->flags === 0 || !$param->var instanceof Expr\Variable || !is_string($param->var->name)) {
                continue;
            }
            $open = $open || self::renames($param->attrGroups, $param);
            $fields[$param->var->name] = self::field($param->var->name, $param->type, $param->default !== null, $param->getStartLine());
        }
        ksort($fields, SORT_STRING);

        return self::listed('fields', array_values($fields)) + ($open ? ['fields_open' => true] : []);
    }

    /**
     * One field: its name, its declared type with class names resolved, whether
     * a deserialiser needs the key, and its line.
     *
     * @return array{name: string, type?: string, required: bool, line: int}
     */
    private static function field(string $name, ?Node $type, bool $defaulted, int $line): array
    {
        $text = self::typeText($type);

        return ['name' => $name, ...($text === null ? [] : ['type' => $text]), 'required' => !$defaulted, 'line' => $line];
    }

    /** A declared type as text, class names fully qualified; null for none. */
    private static function typeText(?Node $type): ?string
    {
        return match (true) {
            $type instanceof Node\Identifier, $type instanceof Node\Name => $type->toString(),
            $type instanceof Node\NullableType => '?' . self::typeText($type->type),
            $type instanceof Node\UnionType => implode('|', array_map(static fn(Node $part): string => $part instanceof Node\IntersectionType ? '(' . self::typeText($part) . ')' : (string) self::typeText($part), $type->types)),
            $type instanceof Node\IntersectionType => implode('&', array_map(static fn(Node $part): string => (string) self::typeText($part), $type->types)),
            default => null,
        };
    }

    /**
     * Whether a serializer attribute, or a serializer tag in the docblock,
     * sits on a class, a property or a promoted parameter.
     *
     * @param list<Node\AttributeGroup> $groups
     */
    private static function renames(array $groups, Node $node): bool
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = ltrim(FrameworkFactStore::name($attribute->name), '\\');
                $short = substr($name, (int) strrpos('\\' . $name, '\\'));
                foreach (self::SERIALIZER_NAMESPACES as $namespace) {
                    if (str_starts_with($name, $namespace)) {
                        return true;
                    }
                }
                if (in_array($short, self::RENAMING_NAMES, true)) {
                    return true;
                }
            }
        }

        return preg_match(self::SERIALIZER_TAG, $node->getDocComment()?->getText() ?? '') === 1;
    }

    /**
     * An enum's cases, sorted by name. Each carries its backing literal as
     * `value` only when every case has a string or integer literal; a pure
     * enum, or one with a computed case, gives none.
     *
     * @return array<string, mixed>
     */
    private static function cases(Stmt\Enum_ $node): array
    {
        $cases = [];
        foreach ($node->stmts as $statement) {
            if ($statement instanceof Stmt\EnumCase) {
                $cases[$statement->name->toString()] = self::literal($statement->expr);
            }
        }
        $backed = $cases !== [] && !in_array(null, $cases, true);
        ksort($cases, SORT_STRING);
        $listed = [];
        foreach ($cases as $name => $value) {
            $listed[] = ['name' => (string) $name, ...($backed ? ['value' => $value] : [])];
        }

        return self::listed('cases', $listed);
    }

    /** A case's backing literal as text: a string, or a signed integer; null for anything else. */
    private static function literal(?Expr $value): ?string
    {
        return match (true) {
            $value instanceof Scalar\String_ => $value->value,
            $value instanceof Scalar\Int_ => (string) $value->value,
            $value instanceof Expr\UnaryMinus && $value->expr instanceof Scalar\Int_ => '-' . $value->expr->value,
            default => null,
        };
    }

    /**
     * A list under its key, cut at `MAX_ENTRIES` and marked when it was.
     *
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private static function listed(string $key, array $entries): array
    {
        return [$key => array_slice($entries, 0, self::MAX_ENTRIES), ...(count($entries) > self::MAX_ENTRIES ? [$key . '_truncated' => true] : [])];
    }
}
