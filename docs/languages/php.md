# PHP

Knossos scans PHP with the bundled PHP worker, which parses each `.php` file
with `nikic/php-parser` and never runs your code. It reads one file at a time:
anything that depends on another file, such as which class a call reaches, is
settled when the facts of every file are put together. The
[Laravel](php-laravel.md) (with [Livewire](php-laravel.md#livewire)) and
[Symfony](php-symfony.md) pages describe what each framework adds on top.

## Variables and closures

A call is attributed to a class when the receiver's type is known in the scope
the call is written in:

- a typed parameter or property, `$this`, or `(new Foo())`, which are certain
- the variable of `catch (Foo $error)`, which is certain; a catch of several
  classes (`catch (Foo | Bar $error)`) leaves it untyped
- a local `$x = new Foo()` or the declared return type of a call, which are
  probable
- `$x ??= new Foo()` when `$x` held no other type, which is probable
- the value of `foreach ($items as $item)` when `$items` is a parameter whose
  docblock names its element type (`@param list<Foo> $items`, `array<K, Foo>`,
  `iterable<Foo>` or `Foo[]`), or of `foreach ($this->items as $item)` when the
  property's docblock does (`@var list<Foo>`), which is probable
- `$x = $y` when `$y`'s type is known, which is probable
- `$result = $run()` when `$run` holds a closure that declares its return type,
  or one returned by a method of the same file documented as
  `@return \Closure(): Foo`, which is probable
- `$x = $typed->property`, including a property declared in another file, so
  that `$x->run()` and `$x->other?->run()` follow the declared property types,
  which is probable

A closure and an arrow function have their own variables. A typed parameter of
`fn (Foo $x) => $x->run()` types its calls even when the method around it has a
`$x` of another type, and an assignment inside a closure does not change the
type of the variable outside. An arrow function sees every variable around it,
and a closure sees its `use` list. After a closure takes a variable by reference
and assigns it, the code around it no longer knows that variable's type.

## Names and case

PHP reads the names of namespaces, classes, interfaces, traits, enums, functions
and methods without regard to case, so `new foo()` builds the `Foo` that another
file declares. Knossos matches these names the same way, including a class name built
at runtime from a namespace literal and a method called on a receiver no scan
could type. Property names stay case-sensitive, as PHP keeps them.

A declared symbol keeps the name its declaration spells, and its ID with it:
the graph does not lower-case names. A name written in another case is matched
only when no exact spelling matches, so code that spells its names as declared
is unaffected. A symbol that nothing in the scan declares, such as `\DateTime`
written in two ways, is one external node, named by the spelling the
declaration of its type uses or else by the first of the spellings in use, in
byte order.

## Processes it starts

An array that reads as the argv of a process links the code that builds it to
the file the process runs, with a probable `launches` edge to that file's
module. The array starts with an interpreter (`PHP_BINARY`, or a literal such
as `'node'`, `'python3'` or `'/usr/bin/php'`) followed by the script's path, or
starts with a path joined to a root (`$root . '/target/release/tool'`). A path
is a string literal, or the literal a concatenation ends with, so
`$root . '/workers/run.js'` names `workers/run.js`; a name with no directory
counts only with a script's extension. The core resolves it to the
module of the project file at that path, or of the one file whose path ends
with it, and drops the edge when there is none. Where the process is started
does not matter: impact on a worker reaches the code that names it, in any
language.

Items spread into the array (`...$args`) are skipped, so the rest of the array
is still read. A command name such as `'orders:poll'` is read only when it
directly follows the script, so `[PHP_BINARY, 'artisan', ...$args, 'orders:hidden']`
yields no command: anything after a spread may not be what it looks like.

## Properties

A read or a write of a property is a `references` edge to the property, so
`list_usages` on a property lists its readers and writers. The edge carries an
`access` attribute: `read`, `write` or `read_write` (`$this->count++`,
`$this->total += 1`). `unset($this->cache)` and `$this->items = []` are writes;
`$this->items[] = $x` and `$this->items['key'] = $x` change the array the
property already holds, so they are `read_write`. A read and a write on one line stay two sites, and each
reader or writer is listed once per access, not once per occurrence. Only a
receiver the scan can type gets the edge: `$this`, a typed variable or
parameter, or `self::`, `static::`, `parent::` or a named class (`Foo::$count`) for a
static property. A
chained access such as `$this->a->b` gets none.

## Visitors and traversers

The hooks of a class that extends `PhpParser\NodeVisitorAbstract` or implements
`PhpParser\NodeVisitor` (`beforeTraverse`, `enterNode`, `leaveNode` and
`afterTraverse`) are called by the traverser, so [dead-code
candidates](../guides/dead-code-candidates.md) never lists them, with or
without `#[\Override]`. A traverser that is handed a visitor
(`$traverser->addVisitor($visitor)` or `new NodeTraverser($visitor)`) gets a
probable `calls` edge to each of those hooks the visitor's class declares or
inherits, so the visitor's hooks have the code that wires them as a caller.
The visitor's class must be known: `new Visitor()`, a typed variable, a
property whose declared type is a class (`$traverser->addVisitor($this->collector)`),
or the class itself handing over `$this`.
