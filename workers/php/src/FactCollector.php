<?php

declare(strict_types=1);

namespace KnossosPhpScanner;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects the language-level facts for one PHP file.
 *
 * Declarations, inheritance, signature types, calls, and instantiations, with light
 * variable-type tracking so `$x = new Foo; $x->bar()` resolves to Foo rather than
 * being dropped. Anything not provable from syntax is recorded at lower confidence
 * or not at all.
 */
final class FactCollector extends NodeVisitorAbstract
{
    use ResolvesClassName;
    use ResolvesDeclarationName;

    /** Programs that run the script named after them, by basename: an argv starting with one launches that script. */
    // BEGIN GENERATED worker-constants:launch_interpreters
    private const INTERPRETERS = ['php', 'node', 'nodejs', 'bun', 'deno', 'python', 'python3', 'ruby', 'perl', 'bash', 'sh', 'zsh'];
    // END GENERATED worker-constants:launch_interpreters

    /** The PhpParser traversers that call a visitor's hooks. */
    private const TRAVERSERS = ['PhpParser\\NodeTraverser', 'PhpParser\\NodeTraverserInterface'];

    /** What a traverser calls on every visitor it is given. */
    private const VISITOR_HOOKS = ['beforeTraverse', 'enterNode', 'leaveNode', 'afterTraverse'];

    /** @var list<array<string, mixed>> */
    private array $nodes = [];

    /** @var list<array<string, mixed>> */
    private array $edges = [];

    /** @var list<array<string, mixed>> */
    private array $diagnostics = [];

    /** @var list<array{id: string, name: string, parent: ?string, interfaces: list<string>, properties: array<string, string>, elements?: array<string, string>}> */
    private array $classes = [];

    /** @var list<array{id: string}> */
    private array $callables = [];

    /**
     * Member names called on a receiver nothing types, keyed by the calling node's id.
     *
     * @var array<string, array<string, true>>
     */
    private array $untypedCalls = [];

    /**
     * How the code accesses the property fetches it assigns to, keyed by node id: `write` for a
     * plain or destructuring assignment or an `unset`, `read_write` for a compound assignment,
     * `++` or `--`, or any write to an element of the array the property holds.
     * A fetch not listed is a read.
     *
     * @var array<int, 'write'|'read_write'>
     */
    private array $propertyWrites = [];

    /** The namespace block the traversal is in: its node id, 0 for a file without one. */
    private int $namespaceScope = 0;

    /** The name of that namespace, empty outside one, which a docblock's unqualified class name is read in. */
    private string $namespaceName = '';

    /** Whether this file's module node has been declared; see {@see self::fileModuleId()}. */
    private bool $moduleDeclared = false;

    /** Class names read out of docblocks, through the file's imports. */
    private readonly DocblockTypes $docblocks;

    /** What each local variable in scope holds, as far as the code shows. */
    private readonly VariableTypeScope $variables;

    /** Infers those variables' classes from the code that binds them. */
    private readonly ReceiverTypeInference $receivers;

    /** Eloquent query builders and factories, and the relations they name. */
    private readonly LaravelEloquentFactCollector $eloquent;

    public function __construct(private readonly string $relativePath)
    {
        $this->docblocks = new DocblockTypes();
        $this->variables = new VariableTypeScope();
        $this->receivers = new ReceiverTypeInference($this->variables, $this->docblocks);
        $this->eloquent = new LaravelEloquentFactCollector($this->variables, $this->resolvedClassName(...));
    }

    /**
     * Index the file's method return types before collecting facts from it.
     *
     * A helper reached only through `$x = $this->make(); $x->use()` otherwise
     * has no inbound edge at all and reads as dead code, which is how this
     * repository's own `server_info` and `diagnose_runtime` entry points came
     * to report the environment methods they call on every request as unused.
     *
     * @param list<Node> $nodes
     */
    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        $scopes = [];
        $namespaces = [];
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_) {
                $scopes[spl_object_id($node)] = $node->stmts;
                $namespaces[spl_object_id($node)] = $node->name?->toString() ?? '';
            }
        }
        foreach ($scopes === [] ? [0 => $nodes] : $scopes as $scope => $statements) {
            $this->docblocks->readImports($scope, $statements);
            $this->receivers->readClosureReturns($scope, $namespaces[$scope] ?? '', $statements);
        }
        $this->receivers->readReturnTypes($nodes);

        return null;
    }

    /** Collect whatever facts this node declares as the traversal enters it. */
    #[\Override]
    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\FunctionLike) {
            $this->variables->enter($node);
            $this->eloquent->enterFunction();
        }
        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->closureSignature($node);
        }
        if ($node instanceof Stmt\Namespace_) {
            $this->namespaceScope = spl_object_id($node);
            $this->namespaceName = $node->name?->toString() ?? '';
        }
        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignRef) {
            $this->markPropertyWrite($node->var, 'write');
        } elseif ($node instanceof Expr\AssignOp
            || $node instanceof Expr\PreInc || $node instanceof Expr\PreDec || $node instanceof Expr\PostInc || $node instanceof Expr\PostDec) {
            $this->markPropertyWrite($node->var, 'read_write');
        }
        if ($node instanceof Stmt\Unset_) {
            foreach ($node->vars as $var) {
                $this->markPropertyWrite($var, 'write');
            }
        }
        if ($node instanceof Stmt\ClassLike) {
            $this->enterClassLike($node);
        } elseif ($node instanceof Stmt\ClassMethod) {
            $this->enterMethod($node);
        } elseif ($node instanceof Stmt\Function_) {
            $this->enterFunction($node);
        } elseif ($node instanceof Stmt\TraitUse) {
            $this->traitUse($node);
        } elseif ($node instanceof Stmt\Property) {
            $this->property($node);
        } elseif ($node instanceof Expr\Assign) {
            $this->receivers->assignment($node, $this->currentClass());
            $this->eloquent->assignment($node);
        } elseif ($node instanceof Expr\AssignOp\Coalesce) {
            $this->receivers->coalescingAssignment($node, $this->currentClass());
        } elseif ($node instanceof Stmt\Foreach_) {
            $this->receivers->foreachLoop($node, $this->currentClass());
        } elseif ($node instanceof Stmt\Catch_) {
            $this->receivers->catchClause($node, $this->currentClass());
        } elseif ($node instanceof Expr\New_) {
            $this->newExpression($node);
        } elseif ($node instanceof Expr\StaticCall) {
            $this->staticCall($node);
        } elseif ($node instanceof Expr\ClassConstFetch) {
            $this->classReference($node->class, $node);
        } elseif ($node instanceof Expr\StaticPropertyFetch) {
            $this->classReference($node->class, $node);
            $this->staticPropertyReference($node);
        } elseif ($node instanceof Expr\Instanceof_) {
            $this->classReference($node->class, $node);
        } elseif ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            $this->methodCall($node);
        } elseif ($node instanceof Expr\FuncCall) {
            $this->functionCall($node);
        } elseif ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) {
            $this->propertyRead($node);
        } elseif ($node instanceof Expr\Array_) {
            $this->launchedFile($node);
        }
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\Property || $node instanceof Stmt\ClassMethod) {
            $this->annotationReferences($node);
        }

        return null;
    }

    /**
     * Classes a Doctrine-style annotation names in a string.
     *
     * `@Gedmo\SlugHandler(class="App\Slug\Handler")` and
     * `@ORM\Entity(repositoryClass="App\Repository\X")` hand a class to a
     * library by name, and nothing else refers to it. Only fully qualified
     * names are taken: a short one would need the file's imports to resolve.
     */
    private function annotationReferences(Node $node): void
    {
        $comment = $node->getDocComment();
        if ($comment === null) {
            return;
        }
        preg_match_all(
            '/\b(?:class|repositoryClass|targetEntity|entityClass|handler)\s*=\s*"\\\\{0,2}([A-Za-z_][A-Za-z0-9_]*(?:\\\\{1,2}[A-Za-z_][A-Za-z0-9_]*)+)"/',
            $comment->getText(),
            $matches,
        );
        $classNames = array_map(static fn(string $className): string => str_replace('\\\\', '\\', $className), $matches[1]);
        // `@AdminEmail` and `@Assert\NotBlank(...)`: an annotation is a class,
        // named through the imports. A tag nothing imports (`@param`) is not.
        preg_match_all('/(?:^|[\s*])@(\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)/m', $comment->getText(), $tags);
        foreach ($tags[1] as $tag) {
            if (str_starts_with($tag, '\\')) {
                $classNames[] = substr($tag, 1);
                continue;
            }
            $imported = $this->docblocks->imported($this->namespaceScope, $tag);
            if ($imported !== null) {
                $classNames[] = $imported;
            }
        }
        foreach (array_unique($classNames) as $className) {
            $this->addEdge('references', $this->currentSource(), self::reference('class', $className), $node);
        }
    }

    /**
     * An argv the code builds to start a process: `[PHP_BINARY, ..., $root . '/bin/worker']`,
     * `['node', 'js/worker.js']`, or `[$root . '/target/release/tool', ...]`. Its script (the
     * first path after an interpreter) or its program (a path in first place) is a file the
     * process runs, so a speculative `launches` edge names it by path; the core resolves the
     * path to that file's module, and drops the edge when the project holds no such file.
     * Where the process is started does not matter: the code that names what runs is what a
     * change to that file affects.
     */
    private function launchedFile(Expr\Array_ $array): void
    {
        $values = [];
        // Values that follow a spread: what comes right after the spread is unknown.
        $afterSpread = [];
        $spread = false;
        foreach ($array->items as $item) {
            if ($item === null || $item->key !== null) {
                return;
            }
            if ($item->unpack) {
                $spread = true;

                continue;
            }
            $afterSpread[count($values)] = $spread;
            $spread = false;
            $values[] = $item->value;
        }
        if ($values === []) {
            return;
        }
        $first = $values[0];
        $interpreter = ($first instanceof Expr\ConstFetch && $first->name->toString() === 'PHP_BINARY')
            || ($first instanceof Node\Scalar\String_ && in_array(basename($first->value), self::INTERPRETERS, true));
        $offset = $interpreter ? 1 : 0;
        $candidates = $interpreter ? array_slice($values, 1) : [$first];
        foreach ($candidates as $index => $value) {
            $path = self::argvPath($value, !$interpreter);
            if ($path !== null) {
                $this->addEdge('launches', $this->currentSource(), self::reference('launched_file', $path), $array, 'probable', ['speculative' => true]);
                $next = $index + 1;
                $named = ($afterSpread[$next + $offset] ?? true) ? null : $candidates[$next] ?? null;
                $this->launchedCommand($path, $named, $array);

                return;
            }
        }
    }

    /**
     * The console command an argv runs by name: `[PHP_BINARY, 'artisan', 'orders:poll']`
     * runs the Artisan command declaring `orders:poll`, and `['php', 'bin/console', 'app:sync']`
     * the Symfony command named `app:sync`. A speculative call, resolved by the core
     * as `Artisan::call()` and `#[AsCommand]` names are, and dropped when no command
     * of the project declares the name.
     */
    private function launchedCommand(string $script, ?Expr $argument, Expr\Array_ $array): void
    {
        if (!$argument instanceof Node\Scalar\String_) {
            return;
        }
        $name = preg_split('/\s/', trim($argument->value), 2)[0] ?? '';
        $kind = match (true) {
            basename($script) === 'artisan' => 'artisan_command',
            $script === 'bin/console' || str_ends_with($script, '/bin/console') => 'command',
            default => null,
        };
        if ($kind !== null && $name !== '' && !str_starts_with($name, '-')) {
            $this->addEdge('calls', $this->currentSource(), 'php:' . $kind . ':' . $name, $array, 'probable', ['speculative' => true]);
        }
    }

    /**
     * The project-relative path an argv element names: a string literal, or the literal a
     * concatenation ends with (`$root . '/bin/worker'`), when it reads as a path to a file.
     * A program in first place must be joined to something, since a bare literal there is a
     * command looked up on PATH; an option (`-d`, `--flag`) is never a path, and a name with no
     * directory is one only with a script's extension (`run.py`).
     */
    private static function argvPath(Expr $value, bool $program): ?string
    {
        if ($value instanceof Expr\BinaryOp\Concat && $value->right instanceof Node\Scalar\String_) {
            $literal = $value->right->value;
        } elseif (!$program && $value instanceof Node\Scalar\String_) {
            $literal = $value->value;
        } else {
            return null;
        }
        $path = ltrim(preg_replace('#^\./#', '', $literal) ?? $literal, '/');
        // `artisan` is a script by name: Laravel's entry point has no extension
        // and sits at the root, where a bare word would otherwise be taken for
        // an argument rather than a file.
        $script = preg_match('#\.(?:py|js|mjs|cjs|ts|php|rb|pl|sh)$#', $path) === 1 || $path === 'artisan';
        if (str_starts_with($path, '-') || (!str_contains($path, '/') && !$script) || str_ends_with($path, '/') || preg_match('#[\s=:]#', $path) === 1) {
            return null;
        }

        return $path;
    }

    /** Unwind scope on the way out, keeping enclosing-class attribution correct. */
    #[\Override]
    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Node\FunctionLike) {
            $this->variables->leave();
            $this->eloquent->leaveFunction();
        }
        if ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            array_pop($this->callables);
        } elseif ($node instanceof Stmt\ClassLike) {
            array_pop($this->classes);
        }

        return null;
    }

    /**
     * The node facts collected from this file.
     *
     * @return list<array<string, mixed>>
     */
    public function nodes(): array
    {
        $nodes = $this->nodes;
        foreach ($nodes as $index => $node) {
            $names = $this->untypedCalls[$node['local_id']] ?? null;
            if ($names === null) {
                continue;
            }
            $names = array_keys($names);
            sort($names);
            $attributes = (array) $node['attributes'];
            $attributes['unresolved_member_calls'] = $names;
            $nodes[$index]['attributes'] = (object) $attributes;
        }

        return $nodes;
    }

    /**
     * The edge facts collected from this file.
     *
     * @return list<array<string, mixed>>
     */
    public function edges(): array
    {
        return $this->edges;
    }

    /**
     * What could not be analysed, reported rather than thrown.
     *
     * @return list<array<string, mixed>>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** Emit the class/interface/trait/enum node and its inheritance edges. */
    private function enterClassLike(Stmt\ClassLike $node): void
    {
        $kind = match (true) {
            $node instanceof Stmt\Interface_ => 'interface',
            $node instanceof Stmt\Trait_ => 'trait',
            $node instanceof Stmt\Enum_ => 'enum',
            default => 'class',
        };
        $name = $this->declarationName($node, $this->relativePath);
        $id = self::reference($kind, $name);
        $parent = $node instanceof Stmt\Class_ && $node->extends instanceof Name
            ? $this->name($node->extends)
            : null;
        $interfaces = [];
        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_) {
            $interfaces = array_map(fn(Name $interface): string => $this->name($interface), $node->implements);
        } elseif ($node instanceof Stmt\Interface_) {
            $interfaces = array_map(fn(Name $interface): string => $this->name($interface), $node->extends);
        }

        $this->addNode($id, $kind, $name, $node->name?->toString() ?? '{anonymous}', $node, [
            'abstract' => $node instanceof Stmt\Class_ && $node->isAbstract(),
            'final' => $node instanceof Stmt\Class_ && $node->isFinal(),
            'readonly' => $node instanceof Stmt\Class_ && $node->isReadonly(),
            'extends' => $parent,
            'implements' => $interfaces,
            'php_attributes' => $this->attributeNames($node->attrGroups),
        ]);

        if ($node instanceof Stmt\Class_ && $node->extends instanceof Name) {
            $this->addEdge('extends', $id, self::reference('class', $parent), $node->extends);
            if (strcasecmp($parent, 'Symfony\\Component\\Validator\\Constraint') === 0 && $node->getMethod('validatedBy') === null) {
                // Validated by `static::class . 'Validator'` unless its own
                // `validatedBy()` says otherwise; kept only when that class
                // exists.
                $this->addEdge('references', $id, self::reference('class', $name . 'Validator'), $node, attributes: ['speculative' => true]);
            }
        }
        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                $this->addEdge('implements', $id, self::reference('interface', $this->name($interface)), $interface);
            }
        }
        if ($node instanceof Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $this->addEdge('extends', $id, self::reference('interface', $this->name($interface)), $interface);
            }
        }

        $this->classes[] = ['id' => $id, 'name' => $name, 'parent' => $parent, 'interfaces' => $interfaces, 'properties' => []];
    }

    /** Emit a method node, its edge to the declaring class, and its signature types. */
    private function enterMethod(Stmt\ClassMethod $node): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            return;
        }

        $name = $class['name'] . '::' . $node->name->toString();
        $id = self::reference('method', $name);
        $attributes = $this->attributeNames($node->attrGroups);
        $this->addNode($id, 'method', $name, $node->name->toString(), $node, [
            'visibility' => $node->isPublic() ? 'public' : ($node->isProtected() ? 'protected' : 'private'),
            'static' => $node->isStatic(),
            'abstract' => $node->isAbstract(),
            'php_attributes' => $attributes,
            ...(self::isVirtualProperty($node, $attributes) ? ['runtime_invoked' => true] : []),
            ...(self::isEloquentConvention($this->typeNames($node->returnType), $attributes) ? ['runtime_invoked' => true] : []),
            ...(self::overridesSupertypeMember($node->name->toString(), $attributes, $class) ? ['overrides' => true] : []),
        ]);
        $this->addEdge('contains', $class['id'], $id, $node);
        $this->callables[] = ['id' => $id];

        $constructor = strtolower($node->name->toString()) === '__construct';
        $this->parametersAndReturn($node->params, $node->returnType, $constructor ? $class['id'] : $id, $constructor);
        $this->documentedElementTypes($node);
    }

    /**
     * Whether JMS Serializer reads this method as a property: `@VirtualProperty`
     * in its docblock or `#[VirtualProperty]`. The serializer calls it by
     * reflection, and no code names it.
     *
     * @param list<string> $attributes
     */
    private static function isVirtualProperty(Stmt\ClassMethod $node, array $attributes): bool
    {
        foreach ($attributes as $attribute) {
            if (str_ends_with('\\' . $attribute, '\\VirtualProperty')) {
                return true;
            }
        }
        $comment = $node->getDocComment()?->getText() ?? '';

        return preg_match('/(?:^|[\s*])@(?:[A-Za-z_\\\\]+\\\\)?VirtualProperty\b/m', $comment) === 1;
    }

    /**
     * Whether Eloquent calls this method by convention: an accessor or mutator
     * declared as an `Attribute` return, or a scope marked `#[Scope]`. The
     * model reads both by reflection and no code names them. The older
     * `getXAttribute` and `scopeX` spellings are told apart by the model
     * chain, which one file cannot see.
     *
     * @param list<string> $returnTypes
     * @param list<string> $attributes
     */
    private static function isEloquentConvention(array $returnTypes, array $attributes): bool
    {
        $normalised = static fn(array $names): array => array_map(static fn(string $name): string => ltrim($name, '\\'), $names);

        return in_array('Illuminate\\Database\\Eloquent\\Casts\\Attribute', $normalised($returnTypes), true)
            || in_array('Illuminate\\Database\\Eloquent\\Attributes\\Scope', $normalised($attributes), true);
    }

    /**
     * Whether a method fulfils a member of a type its class extends or
     * implements, by the source's word or by the language's.
     *
     * `#[\Override]` is the source saying so, and PHP checks it. A built-in
     * supertype (`JsonSerializable`, `Countable`, `IteratorAggregate`) is
     * described by the worker's own runtime, so its members are known without
     * loading anything. A dependency's supertype is neither, and is left to
     * the attribute: guessing from its name would hide a method that only
     * happens to share one.
     *
     * @param list<string> $attributes resolved attribute names
     * @param array{parent: ?string, interfaces: list<string>} $class
     */
    private static function overridesSupertypeMember(string $method, array $attributes, array $class): bool
    {
        if (in_array('Override', $attributes, true)) {
            return true;
        }
        foreach ([$class['parent'], ...$class['interfaces']] as $supertype) {
            if ($supertype === null || (!class_exists($supertype, false) && !interface_exists($supertype, false))) {
                continue;
            }
            $reflection = new \ReflectionClass($supertype);
            if ($reflection->isInternal() && $reflection->hasMethod($method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolved attribute class names, so attribute-driven wiring is visible statically.
     *
     * @param list<Node\AttributeGroup> $groups @return list<string>
     */
    private function attributeNames(array $groups): array
    {
        $names = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $names[] = $this->name($attribute->name);
            }
        }
        return array_values(array_unique($names));
    }

    /** Emit a free function node and its signature types. */
    private function enterFunction(Stmt\Function_ $node): void
    {
        $name = $this->declarationName($node, $this->relativePath);
        $id = self::reference('function', $name);
        $this->addNode($id, 'function', $name, $node->name->toString(), $node);
        $this->callables[] = ['id' => $id];
        $this->parametersAndReturn($node->params, $node->returnType, $id, false);
        $this->documentedElementTypes($node);
    }

    /**
     * Remember the element type a parameter's docblock gives an array or
     * iterable parameter (`@param list<Fact> $facts`), so a loop over it types
     * its value. The native type says only `array`, and a loop over a list of
     * collaborators is how most of them are reached.
     */
    private function documentedElementTypes(Stmt\ClassMethod|Stmt\Function_ $node): void
    {
        $parameters = [];
        foreach ($node->params as $param) {
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $parameters[$param->var->name] = true;
            }
        }
        $class = $this->currentClass()['name'] ?? null;
        foreach (DocblockTypes::documentedParameters($node->getDocComment()?->getText() ?? '') as $name => $type) {
            if (!isset($parameters[$name])) {
                continue;
            }
            $element = DocblockTypes::elementType($type);
            $resolved = $element === null ? null : $this->docblocks->documentedClass($element, $this->namespaceScope, $this->namespaceName, $class);
            if ($resolved !== null) {
                $this->variables->setElement($name, $resolved);
            }
            // `@param array{template: X} $candidate`: what `$candidate['template']` holds.
            $keys = [];
            foreach (DocblockTypes::shapeTypes($type) as $key => $keyType) {
                $keyClass = $this->docblocks->documentedClass($keyType, $this->namespaceScope, $this->namespaceName, $class);
                if ($keyClass !== null) {
                    $keys[$key] = $keyClass;
                }
            }
            if ($keys !== []) {
                $this->variables->setShape($name, $keys);
            }
        }
    }

    /**
     * Emit edges for parameter and return types, which is most of what couples one class to another.
     *
     * @param list<Node\Param> $params
     */
    private function parametersAndReturn(array $params, ?Node $returnType, string $source, bool $constructor): void
    {
        foreach ($params as $param) {
            $types = $this->typeNames($param->type);
            foreach ($types as $type) {
                $this->addEdge($constructor ? 'injects' : 'references', $source, self::reference('class', $type), $param);
            }
            if ($constructor && $param->flags !== 0 && is_string($param->var->name)) {
                $this->promotedProperty($param, $param->var->name, $types);
            }
            if ($types !== [] && is_string($param->var->name)) {
                $this->variables->set($param->var->name, $types[0]);
                if ($constructor && $param->flags !== 0) {
                    $this->setPropertyType($param->var->name, $types[0]);
                }
            }
        }

        foreach ($this->typeNames($returnType) as $type) {
            $this->addEdge('returns', $this->currentCallableId() ?? $source, self::reference('class', $type), $returnType);
        }
    }

    /** Emit `uses` edges; a trait's members otherwise appear to belong to nothing. */
    private function traitUse(Stmt\TraitUse $node): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            return;
        }
        foreach ($node->traits as $trait) {
            $this->addEdge('uses_trait', $class['id'], self::reference('trait', $this->name($trait)), $trait);
        }
    }

    /**
     * Emit a promoted constructor parameter as the property it declares.
     *
     * A promoted parameter is a property like any other, and another file's
     * `$context->options->flag()` is resolved through its declared type, which
     * the reconciler reads from the property's `references` edge.
     *
     * @param list<string> $types
     */
    private function promotedProperty(Node\Param $param, string $name, array $types): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            return;
        }
        $canonical = $class['name'] . '::$' . $name;
        $id = self::reference('property', $canonical);
        $this->addNode($id, 'property', $canonical, '$' . $name, $param, ['promoted' => true]);
        $this->addEdge('contains', $class['id'], $id, $param);
        foreach ($types as $type) {
            $this->addEdge('references', $id, self::reference('class', $type), $param->type ?? $param);
        }
    }

    /** Emit a property node and an edge for its declared type. */
    private function property(Stmt\Property $node): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            return;
        }
        $types = $this->typeNames($node->type);
        foreach ($node->props as $property) {
            $name = $class['name'] . '::$' . $property->name->toString();
            $id = self::reference('property', $name);
            $this->addNode($id, 'property', $name, '$' . $property->name->toString(), $property, self::artisanCommand($property));
            $this->addEdge('contains', $class['id'], $id, $property);
            foreach ($types as $type) {
                $this->addEdge('references', $id, self::reference('class', $type), $node->type ?? $node);
            }
            if ($types !== []) {
                $this->setPropertyType($property->name->toString(), $types[0]);
            }
        }
        // `/** @var list<Command> */ private array $commands`: what a loop over it holds.
        $documented = DocblockTypes::documentedVar($node->getDocComment()?->getText() ?? '');
        $element = $documented === null ? null : DocblockTypes::elementType($documented);
        $resolved = $element === null ? null : $this->docblocks->documentedClass($element, $this->namespaceScope, $this->namespaceName, $class['name']);
        if ($resolved !== null && $this->classes !== []) {
            foreach ($node->props as $property) {
                $this->classes[array_key_last($this->classes)]['elements'][$property->name->toString()] = $resolved;
            }
        }
    }

    /**
     * The Artisan command a console command's `$signature` or `$name` declares,
     * as an attribute of that property: `'orders:poll {--dry-run}'` is `orders:poll`.
     * The core reads it to resolve `artisan('orders:poll')` to the command.
     *
     * @return array<string, string>
     */
    private static function artisanCommand(Node\PropertyItem $property): array
    {
        if (!in_array($property->name->toString(), ['signature', 'name'], true) || !$property->default instanceof Node\Scalar\String_) {
            return [];
        }
        $command = preg_split('/[\s{]/', trim($property->default->value), 2)[0] ?? '';

        return $command === '' ? [] : ['artisan_command' => $command];
    }

    /** Emit an `instantiates` edge for a `new` whose class is statically known. */
    private function newExpression(Expr\New_ $node): void
    {
        if ($node->class instanceof Name) {
            $class = $this->resolvedClassName($node->class);
            $this->addEdge('constructs', $this->currentSource(), self::reference('class', $class), $node);
            if (self::isTraverser($class)) {
                foreach ($node->args as $argument) {
                    $this->traverserHooks($argument, $node);
                }
            }

            return;
        }
        // `new class { ... }` builds the class it declares, under the same
        // path:line name its declaration gets.
        if ($node->class instanceof Stmt\Class_) {
            $this->addEdge('constructs', $this->currentSource(), self::reference('class', $this->declarationName($node->class, $this->relativePath)), $node);

            return;
        }
        // `new ('App\\Cards\\' . $name)` or `new $card` after `$card = 'App\\Cards\\' . $name`:
        // any class in that namespace may be the one built.
        $prefix = match (true) {
            $node->class instanceof Expr\Variable && is_string($node->class->name) => $this->variables->prefix($node->class->name),
            $node->class instanceof Expr => ReceiverTypeInference::namespacePrefix($node->class),
            default => null,
        };
        if ($prefix !== null) {
            $this->addEdge('references', $this->currentSource(), 'php:class_prefix:' . $prefix, $node, 'probable');
        }
    }

    /**
     * Bind a closure's or arrow function's parameters by their declared
     * types, and record the classes its signature names as references of
     * the code that writes it. An untyped parameter shadows a captured
     * variable of the same name.
     */
    private function closureSignature(Expr\Closure|Expr\ArrowFunction $node): void
    {
        $source = $this->currentSource();
        foreach ($node->params as $param) {
            $types = $this->typeNames($param->type);
            foreach ($types as $type) {
                $this->addEdge('references', $source, self::reference('class', $type), $param);
            }
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                if ($types === []) {
                    $this->variables->clear($param->var->name);
                } else {
                    $this->variables->set($param->var->name, $types[0]);
                }
            }
        }
        foreach ($this->typeNames($node->returnType) as $type) {
            $this->addEdge('references', $source, self::reference('class', $type), $node->returnType ?? $node);
        }
    }

    /** Emit a `calls` edge for a static call, resolving `self`/`static`/`parent` against the current class. */
    private function staticCall(Expr\StaticCall $node): void
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
            return;
        }
        $source = $this->currentSource();
        $class = $this->resolvedClassName($node->class);
        $this->addEdge('calls', $source, self::reference('method', $class . '::' . $node->name->toString()), $node);
        $this->classReference($node->class, $node);
        $this->eagerLoads($node, $class);
    }

    /**
     * Edge the Eloquent relations a query or a model on `$class` names in a
     * string (see {@see LaravelEloquentFactCollector::relations()}). The edge
     * is speculative: if the class declares no method of that name, the
     * reconciler drops it.
     */
    private function eagerLoads(Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $node, ?string $class): void
    {
        if ($class === null) {
            return;
        }
        foreach ($this->eloquent->relations($node) as $relation) {
            $this->addEdge('references', $this->currentSource(), self::reference('method', $class . '::' . $relation), $node, 'probable', ['speculative' => true]);
        }
    }

    /**
     * A property read or write on a typed receiver. It references the
     * property, and it may be an Eloquent relation: `$line->product` runs
     * `Line::product()` through the model's magic accessor. Both edges are
     * speculative, so the reconciler keeps only the one whose target the class
     * (or a supertype or trait of it) declares.
     */
    private function propertyRead(Expr\PropertyFetch|Expr\NullsafePropertyFetch $node): void
    {
        if (!$node->name instanceof Identifier) {
            return;
        }
        $class = $this->eloquent->factoryModel($node->var);
        if ($node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $class = $node->var->name === 'this'
                ? $this->currentClass()['name'] ?? null
                : $this->variables->type($node->var->name);
        }
        if ($class !== null) {
            $this->addEdge('references', $this->currentSource(), self::reference('method', $class . '::' . $node->name->toString()), $node, 'probable', ['speculative' => true]);
            $this->propertyReference($class, $node->name->toString(), $node);

            return;
        }
        // Nothing types the receiver, and a relation read this way runs a
        // method of the name, so it counts like an untyped call does.
        $this->untypedCalls[$this->currentSource()][$node->name->toString()] = true;
    }

    /**
     * Remember the property an assignment writes. Going through a `[...]` it appends or indexes
     * edits the array the property already holds, so that is a `read_write`. Every target of a `[$a->x, $b] = ...` or `list(...)` destructuring.
     *
     * @param 'write'|'read_write' $access
     */
    private function markPropertyWrite(Expr $target, string $access): void
    {
        if ($target instanceof Expr\List_) {
            foreach ($target->items as $item) {
                if ($item !== null) {
                    $this->markPropertyWrite($item->value, $access);
                }
            }

            return;
        }
        while ($target instanceof Expr\ArrayDimFetch) {
            $target = $target->var;
            $access = 'read_write';
        }
        if ($target instanceof Expr\PropertyFetch || $target instanceof Expr\NullsafePropertyFetch || $target instanceof Expr\StaticPropertyFetch) {
            $this->propertyWrites[spl_object_id($target)] = $access;
        }
    }

    /** `Foo::$count` and `self::$count` reference the static property they name. */
    private function staticPropertyReference(Expr\StaticPropertyFetch $node): void
    {
        if (!$node->class instanceof Name || !$node->name instanceof Node\VarLikeIdentifier) {
            return;
        }
        $this->propertyReference($this->resolvedClassName($node->class), $node->name->toString(), $node);
    }

    /**
     * A speculative `references` edge to `$class::$name`, in the form property nodes are declared
     * under. `access` says whether the site reads, writes, or (`+=`, `++`) reads and writes it; the
     * core keeps one edge of each.
     */
    private function propertyReference(string $class, string $name, Expr $evidence): void
    {
        $access = $this->propertyWrites[spl_object_id($evidence)] ?? 'read';
        $this->addEdge('references', $this->currentSource(), self::reference('property', $class . '::$' . $name), $evidence, 'probable', ['speculative' => true, 'access' => $access]);
    }

    /**
     * Record that the enclosing symbol names a class in an expression position:
     * `Foo::bar()`, `Foo::CONST`, `Foo::class`, `Foo::$prop`, `x instanceof Foo`.
     *
     * The call and constant edges point at the *member*, so without this a class
     * or enum reached only through its static members has no inbound edge of its
     * own and reads as unreferenced — the same gap parameter and property types
     * already close by edging `references` to the declaring type.
     *
     * A class naming itself is skipped: `self::`, `static::`, and an explicit
     * mention of the enclosing class, in any case, are internal traffic, not usage, and
     * counting them would make every class with one internal static call look
     * reachable. `parent::` resolves to a different class and is kept.
     */
    private function classReference(Node $class, Node $evidence): void
    {
        if (!$class instanceof Name) {
            return;
        }
        $source = $this->currentSource();
        $resolved = $this->resolvedClassName($class);
        if (strcasecmp($resolved, $this->currentClass()['name'] ?? '') === 0) {
            return;
        }
        $this->addEdge('references', $source, self::reference('class', $resolved), $evidence);
    }

    /** Whether `$class` names one of the PhpParser traversers, in any case. */
    private static function isTraverser(string $class): bool
    {
        foreach (self::TRAVERSERS as $traverser) {
            if (strcasecmp($class, $traverser) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * A traverser calls `beforeTraverse`, `enterNode`, `leaveNode` and `afterTraverse` on every
     * visitor it is given, from vendor code that no scan sees. Where the visitor's class is known
     * (built inline, the enclosing class itself as `$this`, or held in a typed variable or
     * property) the call is implied at the place it is given:
     * one probable, speculative `calls` edge per hook, so the core keeps the hooks the class or
     * its ancestors declare and drops the rest.
     */
    private function traverserHooks(Node\Arg|Node\VariadicPlaceholder $argument, Node $evidence): void
    {
        if (!$argument instanceof Node\Arg || $argument->unpack) {
            return;
        }
        $value = $argument->value;
        $visitor = null;
        if ($value instanceof Expr\New_ && $value->class instanceof Name) {
            $visitor = $this->resolvedClassName($value->class);
        } elseif ($value instanceof Expr\Variable && $value->name === 'this') {
            $visitor = $this->currentClass()['name'] ?? null;
        } elseif ($value instanceof Expr\Variable && is_string($value->name)) {
            $visitor = $this->variables->type($value->name);
        } elseif (
            ($value instanceof Expr\PropertyFetch || $value instanceof Expr\NullsafePropertyFetch)
            && $value->var instanceof Expr\Variable
            && $value->var->name === 'this'
            && $value->name instanceof Identifier
        ) {
            $visitor = $this->propertyType($value->name->toString());
        }
        if ($visitor === null) {
            return;
        }
        foreach (self::VISITOR_HOOKS as $hook) {
            $this->addEdge('calls', $this->currentSource(), self::reference('method', $visitor . '::' . $hook), $evidence, 'probable', ['speculative' => true, 'dispatch' => 'PhpParser\\NodeTraverser']);
        }
    }

    /** Emit a `calls` edge, using the tracked variable type to name the receiver where possible. */
    private function methodCall(Expr\MethodCall|Expr\NullsafeMethodCall $node): void
    {
        if (!$node->name instanceof Identifier) {
            return;
        }
        $source = $this->currentSource();

        $class = null;
        // Declared param/property types stay certain; a type inferred from a
        // local `$x = new Y` assignment is only ever probable (the variable may
        // be conditional or reassigned before the call).
        $confidence = 'certain';
        if ($node->var instanceof Expr\New_) {
            // `(new Y())->m()` names its receiver inline, so — unlike a variable
            // assigned earlier — there is nothing that could reassign it before
            // the call. An anonymous class has no name and is skipped.
            $class = $node->var->class instanceof Name
                ? $this->resolvedClassName($node->var->class)
                : null;
        } elseif ($node->var instanceof Expr\Variable && is_string($node->var->name)) {
            if ($node->var->name === 'this') {
                $class = $this->currentClass()['name'] ?? null;
            } else {
                $class = $this->variables->type($node->var->name);
                $confidence = $this->variables->confidence($node->var->name);
            }
        } elseif (
            ($node->var instanceof Expr\PropertyFetch || $node->var instanceof Expr\NullsafePropertyFetch)
            && $node->var->var instanceof Expr\Variable
            && $node->var->var->name === 'this'
            && $node->var->name instanceof Identifier
        ) {
            $class = $this->propertyType($node->var->name->toString());
        } elseif ($node->var instanceof Expr\MethodCall || $node->var instanceof Expr\StaticCall) {
            // `$this->make()->use()`: the receiver is the inner call itself, so
            // its declared return type names it with nothing in between that
            // could have reassigned it.
            $class = $this->receivers->returnedType($node->var, $this->currentClass());
        }
        $this->eagerLoads($node, $class ?? $this->eloquent->builderRoot($node->var));
        if ($class !== null && $node->name->toString() === 'addVisitor' && self::isTraverser($class) && isset($node->args[0])) {
            $this->traverserHooks($node->args[0], $node);
        }

        if ($class !== null) {
            $this->addEdge('calls', $source, self::reference('method', $class . '::' . $node->name->toString()), $node, $confidence);

            return;
        }
        $path = $this->receivers->propertyPath($node->var, $this->currentClass());
        if ($path !== null) {
            // A property of a typed receiver (`$context->options->flag()`),
            // declared in whatever file declares that type, often as a
            // promoted constructor parameter. Resolved by the reconciler from
            // every file's declared property types, and dropped there if a
            // step names no typed property.
            $this->addEdge(
                'calls',
                $source,
                self::reference('method_of_property', $path . '::' . $node->name->toString()),
                $node,
                'probable',
            );

            return;
        }
        $returnedBy = $this->receiverReturnSource($node->var);
        if ($returnedBy !== null) {
            // Named indirectly: the member, and the call whose result it is on.
            // Resolved by the reconciler once every file's return types are known,
            // and dropped there if they do not name a member that exists.
            $this->addEdge(
                'calls',
                $source,
                self::reference('method_of_return', $returnedBy . '::' . $node->name->toString()),
                $node,
                'probable',
            );

            return;
        }
        // Nothing types the receiver. A method by this name may be what the
        // call reaches, so the caller records it for dead-code confidence.
        $this->untypedCalls[$source][$node->name->toString()] = true;
    }

    /** The call a receiver's value came from, whether held in a variable or used inline. */
    private function receiverReturnSource(Expr $receiver): ?string
    {
        if ($receiver instanceof Expr\Variable && is_string($receiver->name) && $receiver->name !== 'this') {
            return $this->variables->returnSource($receiver->name);
        }

        return $receiver instanceof Expr\MethodCall || $receiver instanceof Expr\StaticCall
            ? $this->receivers->calleeReference($receiver, $this->currentClass())
            : null;
    }

    /** Emit a `calls` edge to a free function. */
    private function functionCall(Expr\FuncCall $node): void
    {
        if ($node->name instanceof Name) {
            $this->addEdge('calls', $this->currentSource(), $this->functionReference($node->name), $node);
        }
    }

    /**
     * Flatten a type declaration to class names, walking union, intersection, and nullable forms.
     *
     * @return list<string>
     */
    private function typeNames(?Node $type): array
    {
        if ($type instanceof Name) {
            return [$this->resolvedClassName($type)];
        }
        if ($type instanceof Node\NullableType) {
            return $this->typeNames($type->type);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $types = [];
            foreach ($type->types as $inner) {
                $types = array_merge($types, $this->typeNames($inner));
            }
            return array_values(array_unique($types));
        }

        return [];
    }

    /**
     * Fully-qualified name, honouring the parser's resolved name attribute when
     * present, with `self`, `static` and `parent` read against the current
     * class; see {@see ResolvesClassName::resolvedClassNameIn()}.
     */
    private function resolvedClassName(Name $name): string
    {
        return $this->resolvedClassNameIn($name, $this->currentClass());
    }

    /**
     * The reference a function call names, deferring the ambiguous case.
     *
     * PHP resolves an unqualified call inside a namespace to that namespace's
     * function when one exists and to the global function otherwise — a choice
     * that depends on declarations in other files, which a per-file scanner
     * cannot see. Naming it as written resolved every such call to the global
     * candidate, so a namespaced free function called from its own namespace
     * gained a phantom global twin and carried no inbound edge itself.
     *
     * A `use function` import and a leading backslash are already unambiguous:
     * the parser marks both fully qualified, and they are named outright.
     */
    private function functionReference(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return self::reference('function', $resolved->toString());
        }
        $namespaced = $name->getAttribute('namespacedName');
        if (!$name instanceof Name\FullyQualified
            && $namespaced instanceof Name
            && $namespaced->toString() !== $name->toString()) {
            return self::reference('namespaced_function', $namespaced->toString());
        }

        return self::reference('function', $name->toString());
    }

    /**
     * Record a node fact with its evidence location.
     *
     * @param array<string, mixed> $attributes
     */
    private function addNode(
        string $localId,
        string $kind,
        string $canonicalName,
        string $displayName,
        Node $evidence,
        array $attributes = [],
    ): void {
        $this->nodes[] = [
            'local_id' => $localId,
            'kind' => $kind,
            'canonical_name' => $canonicalName,
            'display_name' => $displayName,
            'origin' => 'ast',
            'confidence' => 'certain',
            'evidence' => $this->evidence($evidence),
            'attributes' => (object) $attributes,
        ];
    }

    /**
     * Record an edge fact with its evidence location, defaulting to `certain`
     * because most edges here are proven by syntax.
     *
     * @param array<string, mixed> $attributes
     */
    private function addEdge(string $kind, string $source, string $target, Node $evidence, string $confidence = 'certain', array $attributes = []): void
    {
        $this->edges[] = [
            'kind' => $kind,
            'source' => $source,
            'target' => $target,
            'origin' => 'ast',
            'confidence' => $confidence,
            'evidence' => $this->evidence($evidence),
            'attributes' => (object) $attributes,
        ];
    }

    /**
     * The file and line span a fact points back to, which is what makes it checkable.
     *
     * @return array{path: string, start_line: int, end_line: int}
     */
    private function evidence(Node $node): array
    {
        return FrameworkFactStore::evidenceIn($this->relativePath, $node);
    }

    /**
     * The innermost enclosing class-like declaration, or null at file scope.
     *
     * @return array{id: string, name: string, parent: ?string, interfaces: list<string>, properties: array<string, string>, elements?: array<string, string>}|null
     */
    private function currentClass(): ?array
    {
        return $this->classes === [] ? null : $this->classes[array_key_last($this->classes)];
    }

    /** The innermost enclosing method or function id, used as an edge source. */
    private function currentCallableId(): ?string
    {
        return $this->callables === [] ? null : $this->callables[array_key_last($this->callables)]['id'];
    }

    /**
     * The node a fact should be attributed to: the callable, else the class, else the file itself.
     *
     * PHP has no module scope, so a procedural script — an entry point, a route
     * file, a tools script — makes its calls from file scope, where neither a
     * callable nor a class encloses them. Attributing those to nothing dropped
     * them outright, leaving anything reached only from a script body with no
     * inbound edge and reading as dead code.
     */
    private function currentSource(): string
    {
        return $this->currentCallableId() ?? ($this->currentClass()['id'] ?? $this->fileModuleId());
    }

    /**
     * The file's own module node, declared the first time file-scope code needs it.
     *
     * Declared lazily so a file that only declares types — which is most of
     * them — contributes no module, keeping the graph free of a node per file
     * that nothing could ever reference.
     */
    private function fileModuleId(): string
    {
        $id = self::reference('module', $this->relativePath);
        if (!$this->moduleDeclared) {
            $this->moduleDeclared = true;
            $this->nodes[] = [
                'local_id' => $id,
                'kind' => 'module',
                'canonical_name' => $this->relativePath,
                'display_name' => basename($this->relativePath),
                'origin' => 'ast',
                'confidence' => 'certain',
                'evidence' => ['path' => $this->relativePath, 'start_line' => 1, 'end_line' => 1],
                // A PHP module node exists only because the file has a body
                // that runs, which by definition is entered from outside the
                // graph — a shell, a web server, a CI step.
                'attributes' => (object) ['executable' => true],
            ];
        }

        return $id;
    }

    /** Remember a property's declared type for resolving calls on `$this->x`. */
    private function setPropertyType(string $property, string $type): void
    {
        if ($this->classes !== []) {
            $this->classes[array_key_last($this->classes)]['properties'][$property] = $type;
        }
    }

    /** The tracked type for a property, or null when it was never declared. */
    private function propertyType(string $property): ?string
    {
        return $this->classes === []
            ? null
            : ($this->classes[array_key_last($this->classes)]['properties'][$property] ?? null);
    }

    /** The local id of a declaration: `php:<kind>:<name>`, without a leading backslash. */
    private static function reference(string $kind, string $canonicalName): string
    {
        return 'php:' . $kind . ':' . ltrim($canonicalName, '\\');
    }
}
