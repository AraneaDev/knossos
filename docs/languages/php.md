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

## Test doubles

In a test file (one under `tests/`, or named like `FooTest.php`), the worker
reports each mock, stub and spy as a `test_double` fact, which the
test-fidelity rules hold against the class it stands in for. It reads
PHPUnit's `createMock()`, `createStub()`, `createConfiguredMock()`,
`createPartialMock()`, `getMockForAbstractClass()` and `getMockBuilder()`,
Mockery's `Mockery::mock()` and `Mockery::spy()` under any alias, Pest's
`mock()`, Laravel's `$this->mock()`, `$this->partialMock()` and
`$this->spy()`, and Prophecy's `$this->prophesize()`. A Laravel facade's
`Cache::shouldReceive()` is one Mockery double per facade, with no class to
hold it against.

The class is the one a `Foo::class` constant names, through the file's
namespace and imports, or a string such as `'App\Foo'`. A class name held in a
variable or built at runtime names nothing. A double assigned to a variable
serves the rest of its method; assigned to a `$this` property in `setUp()`, it
serves every test of the class. Its members are the names `method()`,
`shouldReceive()` and Prophecy's calls configure, with the values
`willReturn()` and `andReturn()` pin them to and the argument counts `with()`
expects. A spy assertion, `$spy->shouldHaveReceived('m')`, names the member
`m`, and the arguments it is given or `->with()` lists after it are counted the
same way. A member whose name is computed, such as `method($name)`, is left
out. In a Pest file, a double that `beforeEach()` assigns to a `$this` property
serves every test of the file.

## Test cases

The same test files report each test as a `test_case` fact. A test is a public
method of a class whose name ends in `Test`, when it is named `test*`, marked
`#[Test]` or documented `@test`, or a closure handed to Pest's `it()` or
`test()`. Each one lists:

- its assertions: PHPUnit's `assertSame()`, `assertEquals()`,
  `assertNotSame()`, `assertNotEquals()`, `assertTrue()`, `assertFalse()`,
  `assertNull()`, `assertCount()` and `assertInstanceOf()`; Pest's
  `expect($x)->toBe(...)` and every other `to*` matcher, through `->not` and
  `->and()`; and Mockery's `shouldHaveReceived()` and `shouldNotHaveReceived()`.
- where each operand's value comes from: a stub's pinned value, a double,
  the code under test, a literal, a parameter of the test (a data provider's or
  dataset's row), or unknown.
- its production calls and the project files they land in.

A local variable is followed to its value only when it is assigned once and
nothing else changes it: no `++` or `.=`, no write to an element or property of
it (`$x[] = 1`, `unset($x['k'])`), no destructuring at any depth (`[[$x]] =`,
`foreach ($rows as [$x])`), no reference, and no call it is handed to.
The rule is coarse on purpose. A function that may write its locals indirectly
(`global`, `extract()`, `get_defined_vars()`, a one-argument `parse_str()`, a
variable variable, an `include` or `require`, `eval()`) proves no local's value. A `$this` property, in a PHPUnit
class or a Pest file, and a static property are never a literal: code the file
does not show (a parent class, a trait's hook, `__set()`, a helper handed
Pest's `test()`) may write them at any time. A property holding a double is
that double, for its identity (`mock-call`), but a call on it never gives the
value pinned on it: a helper or hook may have swapped it for something else.
Only a double held in a local variable, or used inline, gives its pin
(`mock-config`). Any other property assigned code under test in the test or in `setUp()` / `beforeEach()` is
production; any other is unknown. A call on a stub gives its pinned value
only when exactly one way the member answers is set in the test or its setup,
and that is one value for any call: not a value for some arguments only
(`with()`), one of several in turn, or a callback's. A one-value assertion such
as `assertTrue()` has one operand; its failure message is never one. A call counts as production when it constructs a class, calls a
static method of one, or calls a method on a variable bound to `new X()`. It
also counts when it is a method the test case inherits, such as Laravel's
`$this->get('/')`, or a function that is not PHP's own. A class of PHP or of a
test framework, one the test file declares, and one in a test module run no
code under test. The file a counted class lives in comes from composer's PSR-4
maps, read from the nearest `composer.json`; no autoloader is run.

A test file whose doubles or tests cannot be read reports the warning
`PHP_TEST_FACTS_FAILED` and keeps every other fact.
