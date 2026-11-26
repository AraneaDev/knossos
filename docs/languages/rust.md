# Rust

Knossos scans Rust through the same out-of-process scanner protocol as the other
languages. The bundled worker parses `.rs` files with `syn` and never runs
`cargo` or `rustc` against your project.

Rust is optional on a native install: without `cargo` on the machine there is no
Rust worker, and `.rs` files are not scanned. The container image always has
one. `knossos doctor` tells you which workers you have, and warns when the Rust
worker binary was built from other source than your checkout, for example after
a `git pull`. Run `tools/install` to rebuild it.

## What the scanner reads

- `.rs` files
- `Cargo.toml`, recorded as a `cargo` unit that takes part in cache invalidation
- Cargo dependency tables, including target-scoped `*.dependencies` tables and a
  crate's own `[dependencies.<crate>]` sub-table
- `[[bin]]` entries, plus the targets Cargo discovers itself: `src/main.rs` and
  the binaries under `src/bin/`. Discovery follows Cargo's own rules, so
  `autobins = false` turns it off, and on the 2015 edition (the default when
  `edition` is absent) a hand-written `[[bin]]` turns it off too
- the package's build script: the file `build =` names, or a `build.rs` beside
  the manifest, which Cargo runs before it compiles the crate

The manifest's `[package] name` becomes a `package` node when the crate root is
part of the scan. A declared binary path becomes an exact manifest entry point
for classification. If that path is absent, no node is invented.

## What ends up in the graph

| Source construct             | Node kind   |
| ---------------------------- | ----------- |
| File, or a `mod` block       | `module`    |
| Crate manifest `[package]`   | `package`   |
| `struct`, `enum`, or `union` | `class`     |
| `trait`                      | `interface` |
| `fn` inside an `impl`        | `method`    |
| Free `fn`                    | `function`  |
| Framework route declaration  | `route`     |

| Edge         | Source                                               | Confidence             |
| ------------ | ---------------------------------------------------- | ---------------------- |
| `contains`   | nesting, and a `package` containing its crate module | certain                |
| `imports`    | `use`, `extern crate`, and an inline path's module   | certain                |
| `implements` | `impl Trait for Type`                                | certain                |
| `extends`    | supertraits                                          | probable               |
| `calls`      | a resolved call expression                           | probable, see below    |
| `routes_to`  | a route to its handler                               | certain                |
| `references` | a function or type named without being called        | speculative, see below |
| `returns`    | a method to the type its signature declares          | speculative, see below |

A name that a glob import (`use crate::components::*;`) brings in resolves
through it, after the names the enclosing module declares. A type the module
declares shadows the glob for a path below it too (`Widget::new()`), and a
generic type parameter (`struct Gen<Thing>`) shadows any glob-imported type
of its name. A glob source provides a name it declares or binds with a
`use`, or one its own glob imports bring in (`use super::*;` in a child of a
module with `pub use types::*;`), and the name resolves to where it is
declared. A private `use` or glob of the source is followed only for an
importer inside the source, as Rust has it: a parent's `pub use child::*;`
does not bring in what the child itself imports privately. A path through a
module's glob imports (`crate::v2::Profile` under `pub use types::*;` in
`v2`, or `super::digest()`) names the item the same way. A glob of a type
(`use Kind::*;`) brings in its variants, never its associated functions. A name two glob sources provide as
different items is ambiguous and resolves to nothing. A glob source that
provides no such name adds nothing, so `Vec` through `use crate::prelude::*;`
stays the standard library's.

A `use` inside a function body, or inside any block in it, imports for that
block only. Its names are in scope in the whole block, as Rust has it, over a
module-level `use` of the same name, and nothing after the block sees them.
Its path may start from a name the module or the block imports
(`use Kind::{Big, Small};` under `use crate::model::Kind;`). A path starting
from an item the block declares itself names nothing the graph holds, so the
name it binds resolves to nothing in that block. Paths, constructors and
receiver types inside the block resolve through it, and it emits the same
`imports` edge a module-level `use` does.

The same holds for a module-level `use`: its path may start from a name
another `use` of the same module imports, whichever line comes first
(`use m::W;` beside `use crate::m;` imports `crate::m::W`, and
`use collections::HashMap;` beside `use std::collections;` imports
`std::collections::HashMap`), or from an item the module declares
(`use Kind::A;` beside `enum Kind`). A name a module both declares and imports
belongs to `#[cfg]` alternatives (`pub use LazyCell as LazyLock;` beside a
`struct LazyLock` under another `cfg`), so it keeps naming the declared item and
re-exports nothing.

A call whose callee is named in UpperCamelCase builds a value: `Wrapper(1)`
constructs a tuple struct and `Error::Io(e)` an enum variant. Neither is a
`calls` edge; each is a `references` edge to the type it builds. A call into
your own crates is speculative, since its kind (function or method) is read
from the name: the edge is kept when the graph declares that target and
dropped otherwise, rather than becoming an external symbol. A call into
another crate keeps its external target.

A method of a trait impl is named below its impl, so it stays apart from an
inherent method of the same name and from another trait's:
`impl Driver for Session` declares `crate::session::Session::<impl Driver>::receive`
beside an inherent `crate::session::Session::receive`, and
`impl From<u8> for Id` and `impl From<u16> for Id` declare
`Id::<impl From<u8>>::from` and `Id::<impl From<u16>>::from`. A call names the
method by its type, as Rust code does (`Session::receive(..)`,
`session.receive()`): it reaches the inherent method when there is one, and
otherwise the one trait impl method of that name, whichever file declares it.

A declaration compiled only under a `cfg` other than `test` carries that
predicate as its `cfg` attribute: the item's own (`#[cfg(target_os = "linux")]
impl Runtime`), and every item in a file whose `mod` declaration is gated
(`#[cfg(windows)] #[path = "svc/scm.rs"] mod scm;`). Declarations of one item
that are all gated this way are `cfg` alternatives: no build compiles two of
them, so they are one node, with every alternative's edges, and no
`reconciler.duplicate_symbol_evidence` warning. The first declaration's file
is the node's evidence, and `cfg_alternatives` lists the others with their
lines and predicates.

A `use` that binds one name under exclusive `cfg`s
(`#[cfg(unix)] use unix as platform;` beside
`#[cfg(windows)] use windows as platform;`, at module level or in a function
body) binds it to each alternative, so `platform::flush_file()` is a call to
both `crate::fs::unix::flush_file` and `crate::fs::windows::flush_file`. The
same holds for a re-export through such a name (`pub use platform::open;`)
and for gated re-exports of one name from different modules: a path through
it reaches every alternative, and an `impl` of such a name
(`impl OwnedChild` under `#[cfg(unix)] pub use unix::OwnedChild;` and a Windows
one) adds its methods to each. A private `use` is a path from the module's
descendants too, so `use super::platform;` in a child module names what the
parent's `use` imports.

A node's `local_id` and both ends of an edge are written
`rust:<kind>:<canonical>`. `canonical_name` itself carries no prefix. A raw
identifier is named without its `r#`: `mod r#async;` is the module
`crate::async`, the module of `src/async.rs`. When a
target cannot be resolved, Knossos keeps no edge rather than a guess. The worker
reports every site; the core collapses repeated edges to one per kind, source,
target and owner, keeping the surest site's evidence (see
[Repeated edges](../reference/scanner-protocol-v1.md#repeated-edges)).

### Code nothing calls by name

These get an attribute, so they stay off the
[dead-code candidates](../guides/dead-code-candidates.md) list:

- A crate-root `fn main` marks its module `executable`. Manifest entry-point
  classification can additionally mark every node from an exact binary path with
  `application.entry_point`.
- A function exported to a foreign caller (`#[no_mangle]`, `#[export_name]`,
  `#[wasm_bindgen]` or an `extern` ABI) is `runtime_invoked`. So are the public
  methods of a `#[wasm_bindgen] impl`, and `drop` in an `impl Drop`.
- A method of a trait impl carries `overrides`, because the trait declares it
  and the trait may be a dependency's. It is named below its impl, see above.
  A trait impl that is a block of its own (`unsafe impl NativeValue for u32 {}`)
  carries `trait_impl`: it is used wherever a bound on the trait is met, which
  leaves no edge to it, so it is never a dead-code candidate on its own.
- Code compiled only in a test build and `#[test]` functions (including
  `#[tokio::test]`) are marked as test code. The `cfg` predicate is evaluated:
  `cfg(test)` and `cfg(all(test, feature = "x"))` mark an item (a module, a
  function, an `impl`, or a single method) and everything in it, while `cfg(not(test))` and `cfg(any(test, feature = "x"))` stay production
  code, since a production build compiles them too.
- Code compiled only for the tests through a test-support feature is marked
  `test_support`: an item gated by a feature whose name contains
  `test-support` or `test_support`, alone or beside `test` in an `any(..)`
  (`#[cfg(feature = "test-support")]`), and every item in a module a `mod`
  declaration gates that way. A crate exports such code for its own and its
  dependents' tests, so it is never a dead-code candidate, and
  `excluded_test_support` counts it. `cfg(not(feature = "test-support"))`
  stays production code.

### Speculative edges

Some names could refer to a type the graph does not hold, such as `Vec` or
`String`. For those the worker emits a `speculative` edge, and the core keeps it
only if the target turns out to be a declared node. That is how a method call on
a receiver of known type reaches its method: `self`, a typed parameter or
closure parameter (`|s: &Store|`), a `let` with a type annotation, a `let`
assigned from a struct literal or an associated call such as `Widget::make()`,
or a parameter typed `dyn Trait` or `impl Trait`, whose methods are the
trait's, a binding a struct pattern takes from a field
(`let Index { store, .. } = index;`), also in a `match` arm, an `if let` or
`while let` chain or a `for` pattern. Each binding lasts as long as its scope,
a plain block included. A type behind `&`, `&mut`, `Box<T>`, `Rc<T>` or
`Arc<T>` is `T`, except for a method the pointer itself has (`clone`, `as_ref`,
`downgrade` and the like), which is left untyped. A call on what another call
returns (`state.mode().label()`) resolves through the declared return type. A
call through a field (`self.walk.facts.edge()`) resolves through the field's
declared type, also when the struct, its `impl` block and the field's type sit
in three different files: the declaration index holds every struct's field
types as the declaring file's own imports resolve them. A field type that file
names only through a glob import (`use crate::prelude::*;`, `use super::*;`) is
kept as the bare name with the glob's source modules and resolved through the
whole index when a call looks it up, so the caller's file reads the glob
sources it consulted. `#[cfg]` alternatives of one struct keep only the field
types they agree on.

A path that reaches an item through a re-export (`crate::visit::collect()`
under `pub use cfg::collect;` in `visit`) names the item where it is declared,
`crate::visit::cfg::collect`, so the edge lands on the node the graph holds.
Any `use` re-exports, `pub(crate)`, `pub(super)` and a private one (which the
module's descendants reach through `super::`) included, and a chain of them is
followed to its end, also through a module that re-exports an
item under its own name (`pub use parse::parse;` beside `mod parse;`). A longer
path through such a name (`parse::helper()`) goes through the module. The
`imports` edge still names the module the source wrote.

`extern crate a as b;` reads as `use ::a as b;`, so a facade crate's
`pub extern crate grep_searcher as searcher;` is a re-export like any other:
`grep::searcher::Searcher` names `grep_searcher::Searcher`. A path written
inline, without a `use` line (`grep::cli::resolve_binary(..)`), imports the
module it reaches as a `use` of it would, so the file depends on that module.
Only a path that resolves to a module of the project counts, not one that
guesses.

A prelude trait named bare (`impl From<u8> for Str`, `trait Named: Clone`)
that the module neither declares nor imports is the standard library's
(`std::convert::From`), never a trait of the enclosing module or the crate
root. A trait outside the prelude (`Display`, `Hash`, `FromStr`) is in scope
only through an import, which is how it resolves. Likewise a trait impl for a
primitive or prelude type named bare (`impl PartialEq<u8> for String`,
`impl Shout for str`) is a block of its own, `<impl PartialEq for String>`,
since the crate does not own the type, never a `String` of the enclosing
module. A blanket impl, whose type is a parameter or a reference to one
(`impl<M: Matcher> Matcher for &M`), is a block of its own the same way,
`<impl Matcher for &M>`, and implements the trait. A prelude or primitive name
written bare (`Option`, `str`, `Some`) that the module neither declares nor
imports is the standard one, so it is no `references` target in the module.

## Frameworks

The core reads your Cargo manifests and tells the worker which of `axum`,
`actix` and `rocket` to enrich. You can set the same hints under `frameworks` in
your [project configuration](../get-started/project-configuration.md). Route
enrichment only runs for the frameworks it is asked for.

- Axum: `Router::route("/path", get(handler))` calls produce `route` nodes and
  `routes_to` edges. A route inside `nest("/api", Router::new().route(...))`
  is served under `/api`. A `nest("/api", api())` or `merge(api())` whose
  router another function builds is a `mounts` edge to that function, with
  the prefix, and every call route carries the function that builds its
  router as `router`. The core puts each nest's prefix in front of that
  router's routes, through any number of levels; a router nothing nests is
  served as it is.
- Actix: route attributes such as `#[get("/path")]`, `#[post(...)]` and
  `#[route("/path", method = "PUT")]`, the
  `web::resource(...).route(web::get().to(handler))` shape, and
  `route("/path", web::get().to(handler))` on an app or scope. A route under
  `web::scope("/api")`, chained on it or passed to its `service(...)`, is
  served under `/api`, through nested scopes too.
- Rocket: verb attributes such as `#[get("/path")]`.

A router held in a variable, an actix `configure(...)` function, and an
attribute route registered with `service(handler)` inside a scope do not take
a prefix.

A handler function gets the `rust_framework_roles` attribute with
`rust.route_handler`. A route path that is not a string literal is skipped
with the `RS_DYNAMIC_ROUTE_PATH` warning. A literal with parameters such as
`/users/:id`, `/users/{id}` or `/users/<id>` is a template and kept as written,
so a caller's `fetch` of `/users/42` binds to it like any other route.

## Cross-file resolution

Each scan request builds a declaration index before it walks the files. The
index lets a cross-file `impl` block attach its methods to a uniquely declared
type, and lets a call into a child module point at a declaration in another
file. An `impl` block's methods are indexed on the type the block's own file
imports (`impl BrokerState` under `use super::BrokerState;` in a child module
adds to the parent's type), so `BrokerState::accepted_owned()` reaches a method
declared in another file. An ambiguous declaration is dropped rather than
guessed, except that declarations of one path that each compile only under a
`cfg` count as one. The index holds
every discovered `.rs` file of the project, read from disk within the byte cap,
whichever files the request names, so a file rescanned on its own resolves the
same names a full scan resolves. A file that does not parse adds nothing to it.
The worker keeps what each file gave the index for as long as its process
lives, keyed by the file's module and the hash of its bytes, so a later batch
reads and hashes every file but parses only the ones that changed. While
indexing takes long, it sends `scan/heartbeat`.

A file's module path follows from where it sits, not from `mod` declarations,
so a module file no `mod` declares is indexed like any other. `src/lib.rs` and
`src/main.rs` are the crate root (`src/main.rs` is `crate::main` beside a
library); any other `lib.rs` or `main.rs` is a module of its own, so
`src/net/lib.rs` is `crate::net::lib` and `src/bin/tool/main.rs` is
`crate::bin::tool::main`. Outside `src/`, a file keeps its directory chain
(`tests/smoke.rs` is `tests::smoke`), and only files under `tests/`,
`examples/` and `benches/` enter the index, since Rust reaches no other file
there without `#[path]`. A file elsewhere outside `src/`, such as
`crate/engine.rs`, keeps its own nodes but is not indexed, and its directory
chain can give it the same id as a file under `src/` (`crate::engine`, the
module of `src/engine.rs`).

The package at the project root is rooted at `crate`, and a workspace member
at its crate name (`crates/core-lib/src/app.rs` is `core_lib::app`). Code
outside a library names it by its crate name: the `[lib] name` in the
manifest, else the package name with dashes as underscores. Such a path is
placed on the library's root, so `my_demo::run()` in `src/main.rs`,
`src/bin/`, `tests/`, `examples/` or `benches/` calls `crate::run`, the
function `src/lib.rs` declares.

A `mod name;` declaration names the module of the file Rust loads for it, as
that file is placed. A crate root and a `mod.rs` keep their children beside
them, so `mod cli;` in `src/main.rs` is `crate::cli` (`src/cli.rs`), and
`mod common;` in `tests/it.rs` is `tests::common`. A file a `#[path]` declaration loads, outside a crate root, is the module the
declaration names, and so is every file below it: `src/mcp/budget.rs`'s
`#[cfg(test)] #[path = "budget_tests.rs"] mod tests;` makes
`src/mcp/budget_tests.rs` the test module `crate::mcp::budget::tests`, whose
`use super::*;` reaches `budget`'s items, and `src/broker.rs`'s
`#[path = "broker_activation.rs"] mod activation;` makes
`src/broker_activation.rs` `crate::broker::activation`. Such a file keeps the
files its own `mod` declarations load beside it, as a `mod.rs` does. One name
declared under exclusive `cfg`s that loads two files
(`#[cfg(unix)] #[path = "native/unix.rs"] mod native;` beside a Windows one)
places both in that module, as `cfg` alternatives of each other. In a crate
root, `#[path = "x.rs"]` names the module of `x.rs`, and a path through the
declared name reaches it, in that file and in any other (`crate::a::x` under
`#[path = "impl_a.rs"] mod a;` is `crate::impl_a::x`).

Each binary in `src/bin/`, integration test, example and benchmark is a crate
of its own, and its `crate::` names that crate, whose root is the file itself:
`crate::own` in `src/bin/tool.rs` is the `crate::bin::tool::own` it declares,
and through its `mod helper;`, `crate::helper` is `crate::bin::helper`
(`src/bin/helper.rs`); `crate::common` in `tests/it.rs` is `tests::common`.
Only the target's root file knows this; a module file below `src/bin/<name>/`
could belong to that binary or to another, so its own `crate::` paths still
start at the package's `crate`. An out-of-line `#[cfg(test)] mod name;` marks
the file it loads as test code, its module node included, when the declaring
file sits in a module above it, or is the crate root, also when a `#[path]`
sends it to a sibling (`src/net.rs` loading `src/net_tests.rs` as
`crate::net::tests`). A file an `include!("..")` pulls in under
`#[cfg(test)]` (`mod tests { include!("../shared/cases.rs"); }`) is test code
too, and reads the file that includes it.

### What an incremental scan rescans

Each contribution names the files its facts were read from, so an incremental
scan rescans a file only when one of those changes:

- every file of every module above a name the walk asked the index about,
  found or not, including the paths such a file could have and that do not
  exist yet (`src/engine/sign.rs` and `src/engine/sign/mod.rs`, a member's
  `src/lib.rs`);
- every file of every module above the file's own, since a
  `#[cfg(test)] mod name;` there decides whether the file is test code, and a
  `#[path]` declaration there where the file sits in the module tree;
- for a file no `mod` declaration loads, the other files of its directory and
  of the one above it, since a `#[path]` declaration there would place it;
- the `src/lib.rs` and `src/main.rs` of the package whose `src/` holds the
  file, or a file one of its `mod` declarations loads, since whether they
  exist decides those module paths.

Editing `src/engine/sign.rs` therefore rescans the files that looked up a name
in that module and the files below it, not unrelated modules. Every name is
looked up below the crate root, so editing `src/lib.rs`, or adding a
`src/main.rs` or `src/lib.rs`, rescans the whole crate: that follows from what
the files read, not from a rule. A file that does not parse has no facts and
reads nothing beyond itself, so it stays an ordinary attributed row and is
rescanned only when it changes. Editing a `Cargo.toml` rescans every Rust file.
A rebuilt file reaches its readers only when its own bytes changed: what a file
gives the index (its declarations, the names its `use` items bind and
its structs' field types) follows from its own bytes and the package layout, so
no file's facts depend on what another file read. A name followed through a
re-export or a field type is a lookup like any other, so the file declaring it
is read.

## Test doubles

A file holds test code when the walk marked any of its items as a test (see
[Code nothing calls by name](#code-nothing-calls-by-name)), or when it sits
under a Cargo `tests/` directory. In such a file the worker reports each mock
or fake as a `test_double` fact. In a Cargo package, a file that no `mod`
declaration loads is compiled by no test run, so it reports none. Without
`#[path]`, `mod name;` loads `name/mod.rs` as much as `name.rs`, so neither is
such a file. A tree with no `Cargo.toml` has no build to leave a file out of:
every `.rs` file in it that holds test code reports its tests and doubles,
stray fixtures included. Adding any manifest makes the files no `mod` loads
report none.

These are the frameworks it reads, without compiling anything:

| Crate        | Double made by                                                                                                 | Member api          | Pins                                        | `mock-config` value                                               |
| ------------ | -------------------------------------------------------------------------------------------------------------- | ------------------- | ------------------------------------------- | ----------------------------------------------------------------- |
| mockall      | `MockS::new()`, `MockS::default()` or `Default::default()` of a mock `#[automock]` or `mock!` generates        | `expect`, `context` | `return_const`, `returning`, `return_once`¹ | yes                                                               |
| hand-written | an `impl T for Fake` in test code, for a project trait `T`                                                     | `impl`              | a method whose body is one literal          | yes                                                               |
| faux         | `Foo::faux()`                                                                                                  | `when`              | `then_return`                               | yes, for one `when!` chain whose only value verb is `then_return` |
| mry          | `mry::new!(S { … })`, a `#[mry::mry]` struct or trait's constructor; `S::mock_m(…)` and `mock_f(…)` as statics | `mock`              | `returns`                                   | yes, when every matcher is `mry::Any`                             |
| mockiato     | `XMock::new()` or `default()` of the file's `#[mockable]` trait                                                | `expect`            | `returns`, `returns_once`                   | yes                                                               |
| mockers      | `scenario.create_mock::<…>()` and its `_for`, `named` forms                                                    | `expect`            | `and_return`                                | never                                                             |
| mocktopus    | `f.mock_safe(…)` or `f.mock_raw(…)`                                                                            | `mock_fn`           | `MockResult::Return`                        | never                                                             |
| mock_derive  | `MockT::new()` of the file's `#[mock]` trait; `Extern<Abi>Mocks::method_f()` as statics                        | `method`            | `set_result`, `return_result_of`            | never                                                             |
| galvanic     | `new_mock!(Trait)`                                                                                             | `given`             | `then_return`                               | never                                                             |

¹ And their `_st` forms; `return_once` pins once.

A mockall double is one mock instance, made by its constructor, never by the
generating attribute or macro. `#[automock]` on a trait `T`, or on an impl of
`S`, generates `MockT` or `MockS` in the generator's module, and so does
`mock! { S … }`; `#[automock] mod m` generates `mock_m`, whose functions are
static contexts. A mock's name resolves as a type path does, through the
file's own module, its `use` items and its globs. Under a `cfg` that requires
`test` it is visible only inside its own crate, so a `tests/` target that names
it gets no double. A name no generator makes (a `MockRepo` by convention only)
makes no double. Each double has a `doubles` edge to the trait or type its
generator names; a `mock!` block has one only when it holds exactly one
`impl T for S` section.

A static context (`MockS::m_context()`) is shared by every test of a process,
so it gives the double's identity and never a value. Each `expect_*` chain
gives the member it configures, and each value verb of the chain a pin. A
double's `invocations` count the calls made on its handle and the places it is
handed on: as an argument, borrowed, moved into a struct, a `Box`, an `Arc` or
an `Rc`, or returned.

A hand-written fake is a struct or enum declared in test code with an
`impl T for Fake` block, where `T` is a trait the project declares. Each block
is a double of its own, at its `impl` token, whose members are its `fn` items.
A member pins its value only when nothing could give the fake another answer
for that name: its body is one literal or `return LIT;`, no attribute sits on
the method, the block or (other than docs, lints, `repr` and the standard
derives) the fake, the fake is private and declared once in a module with no
child module, no other impl in the file declares the name for it, no macro in
the file could declare one, and no other trait in scope may take the call.

HTTP server mocks (mockito, wiremock, httpmock) give no facts. They stand in
for a remote service, not for a symbol of the project.

## Test cases

The same files report each test as a `test_case` fact: a `fn` with an
attribute whose path ends in `test` (`#[test]`, `#[tokio::test]`,
`#[async_std::test]`, `#[sqlx::test]`, …), `#[rstest]`, `#[test_case(…)]`
(one fact per `fn`, not per case) or `#[wasm_bindgen_test]`. Its `name` is
the module path from the file's module (`tests::adds`), and
`#[should_panic]` marks it as expecting a failure. Each lists:

- its assertions: `assert_eq!` and `assert_ne!` with two operands, `assert!`
  on one comparison (`==`, `!=`, `<`, `<=`, `>`, `>=`) or one value, and
  `assert!(matches!(x, P))` as `matches`, each in its `debug_` form too.
  Message arguments are never operands. `pretty_assertions`' `assert_eq!` and
  `assert_ne!` count as the standard ones. mockall's `.times(…)`, `.once()`
  and `.never()` assert the double they configure, since mockall checks them
  when the mock is dropped;
- where each operand's value comes from: a literal, a call on a double
  (`mock-call`), the value a double was pinned to (`mock-config`), the code
  under test (`production`), a parameter of the case (`rstest`, `test_case`,
  `sqlx::test`), or unknown;
- its production calls and the project files they land in.

An assertion macro is read only while its name is the standard one. A
`macro_rules!` of that name anywhere in the package, a glob or import that can
bring one in, `#[macro_use] extern crate`, a local `mod core` or a `#[path]`
to a file the scan does not read leaves it an ordinary macro, which asserts
nothing. To know that, a test file reads every file of its package (G5-05).

A literal is a Rust literal token, a negated number, or a tuple or array of
them; a number is compared by its value (`42u32`, `0x2a` and `42` are one
literal). A local name is followed to a literal only when the test binds it
once, with no `mut`, as a top-level `let` before the assertion, no `unsafe`
block sits in the function, and no macro other than an assertion is handed the
name. A `const` or non-`mut` `static` of the same file whose initializer is a
literal is followed too. A `static mut`, a field and every call are unknown.

A call on a double gives its pinned value (`mock-config` with `value`) only
for a handle bound once to its constructor, with exactly one `expect_*` chain
for that member, before the assertion, that carries one literal value verb,
and nothing that could change what the double answers: a `&mut` borrow, an
assignment, a move, a closure capture, a shared borrow handed on or a clone
(for the frameworks other than mockall and a hand-written fake), a macro naming
the handle, `checkpoint()`, a call of a member it did not configure, an
`unsafe` block, or a macro in item or statement position.

A framework's mock gives no value for a member when another trait in scope may
take the call. A blanket `impl<X> Ext for X` with `fn m(self)` takes `mock.m()`
by value before any `m(&self)` is tried, the mocked trait's or an inherent one
alike, so the assertion checks `Ext::m`, not the stub. This holds for every
mock: an `#[automock]` trait, trait impl or inherent impl, both sections of a
`mock!`, faux, mry and mockiato. The traits the mock implements are ruled out;
a mock of inherent methods (faux, an mry struct, an `#[automock] impl S`)
implements none to rule out. The value is dropped when any of these holds:

- the member is named like a standard blanket method (`into`, `to_string`,
  `borrow`, …);
- the file declares another trait with a method of that name;
- a `use` inside a function body leaves the standard crates;
- a macro other than `macro_rules!`, `thread_local!` and mockall's `mock!`
  stands where an item may in a module of the file that holds a test or that
  the file globs, or inside a trait of the file, since it may declare a trait
  or a method;
- the file imports a name the declaration index holds as a trait other than
  a mocked one, or one outside the standard crates the index cannot place;
- it globs a module, other than one of its own, that declares or re-exports
  such a trait, or that the index does not know, or where a macro stands
  where an item may.

mockall's macros, `Sequence` and predicate module, `mockiato::mockable`,
`mry::mry`, `mry::new`, `mry::m`, `mry::Any`, faux's `create`, `methods` and
`when`, and `async_trait::async_trait` are known to be no trait. Each lookup is
a read of the file that could declare the name, so adding a trait to an
imported file judges the value again. A hand-written fake has its own rule (see
[Test doubles](#test-doubles)).

A call counts as production when the walk resolved it to a project declaration
outside test code, or when it is a method call on a receiver the walk could not
type (`thing.go()`, `"1".parse()`), unless it is a call on a double or a
framework verb. A path call the walk did not resolve to the project
(`String::from`) and a macro count for nothing. Calls inside a double's
configuration (`eq(1)`, `MockResult::Return(v)`) never count.

A test file whose doubles or tests cannot be read reports the warning
`RS_TEST_FACTS_FAILED` and keeps every other fact.

## How the rules judge Rust tests

Rust checks a test double against the code it replaces when it compiles the
test (`cargo test`). So every Rust double carries `compile_checked: true`,
and the drift rules skip it (user decision Q1): a finding would repeat the
compiler's error, and would be false wherever Knossos misread a macro or a
`cfg`.

| Category            | Rust construct                                                                  | Compiler      |
| ------------------- | ------------------------------------------------------------------------------- | ------------- |
| `GHOST_MEMBER`      | `expect_foo()` on an `#[automock]` or `mock!` double after the trait lost `foo` | E0599         |
| `GHOST_MEMBER`      | a fake's `impl T for Fake { fn gone() }`                                        | E0407         |
| `GHOST_MEMBER`      | the proc-macro crates' `when!`, `mock_<m>` and `expect_<m>` of a lost member    | E0599         |
| `ARITY_MISMATCH`    | `.with(p1, p2)` on a one-parameter method                                       | E0061         |
| `ARITY_MISMATCH`    | a `.returning(\|a, b\| …)` closure of the wrong arity                           | E0593         |
| `ARITY_MISMATCH`    | a fake method of the wrong arity                                                | E0050         |
| `RETURN_DRIFT`      | `.return_const("x")` for `-> u32`, a fake returning the wrong type              | E0308 / E0277 |
| `VISIBILITY_BREACH` | a mock of a private trait, or of a method from another module                   | E0603 / E0624 |

The assertion rules judge Rust tests as they judge the other languages':

| Category                    | Rust construct                                              | Confidence |
| --------------------------- | ----------------------------------------------------------- | ---------- |
| `TAUTOLOGY/self_comparison` | two equal literals compared (`assert_eq!(7, 7)`)            | definite   |
| `TAUTOLOGY/constant`        | an assertion on literals only                               | definite   |
| `TAUTOLOGY/mock_echo`       | a double's pinned value compared with itself                | definite   |
| `TAUTOLOGY/mock_only`       | a test that checks only doubles and runs no production code | warning    |
| `TAUTOLOGY/zero_reach`      | a pinned double nothing invokes or asserts                  | warning    |

Every Rust comparison goes through `PartialEq`, which need not be reflexive
(a NaN, a custom `eq`), so `assert_eq!(x, x)` is a self-comparison only when
both sides are the same literal. `unconfigured_spy` does not apply, since no
Rust framework makes a spy: mockall panics on a call it has no expectation
for. A `times` assertion counts as asserting its double, so such a double is
never `zero_reach`.

### Known false definites

A healthy test should give no definite finding. These cases still can, and
each is accepted as contrived. The IDs are the deferred-gaps tracker's:

- **A nested `Cargo.toml`** (G5-04). A manifest inside another package's
  module tree, reached by a plain `mod`, keys the files below it to its own
  package while the outer crate compiles them, so a macro of the outer
  package that shadows `assert_eq!` there goes unseen. `cargo package` leaves
  such a manifest out.
- **A renamed assertion crate** (G5-07). `extern crate x as
pretty_assertions`, or a Cargo `package =` rename to `pretty_assertions`,
  is trusted as `pretty_assertions`, whose `assert_eq!` compares. A crate
  renamed to `mockall`, `mockiato`, `mry`, `faux` or `async_trait` is trusted
  in the same way, as holding no trait that could take a mock's call.

### What the Rust rules do not see

These are accepted, and each costs a missed finding, as stated. The IDs are
the deferred-gaps tracker's:

- **Drift the compiler rejects** (Decision 1, user decision Q1). The cases in
  the table above report nothing.
- **A `mock!` struct section whose namesake lost a member** (G5-01, user
  decision Q2). `mock! { pub Store { fn purge(&self); } }` compiles after
  `Store` loses `purge`, since `purge` is the mock's own. Judging it needs a
  complete member list of a Rust type, and Rust nodes send none.
- **Drift in code the default build skips** (G5-13): a test under a feature
  that is off by default, or for another target, which `cargo test` never
  compiles locally.
- **`MOCKS_SUBJECT`** (G5-11). Rust doubles carry their `test_case`, but the
  rule that a test replaces its own subject does not run for Rust.
- **HTTP server mocks** (G5-12, user decision Q3): mockito, wiremock and
  httpmock give no facts.
- **Tests inside macros and doctests** (G5-14): a test in `proptest! {}`,
  `quickcheck!` or a project macro, and every doctest, are not read.
- **Signatures for synthesis** (G5-15): Rust signatures carry no visibility,
  receiver, `static` or `async`.
- **A `const` from another file** (G5-16) is unknown to literal tracing.
- **A mock reached through a re-export** (G5-17, `pub use inner::MockFoo`)
  that the walk does not follow makes no double.
- **Generators outside the walked file** (G5-08). Outside mockall, a
  generator (`#[faux::create]`, `#[mry::mry]`, `#[mockable]`, `#[mock]`) is
  seen only in the file the double is made in. One in another file gives the
  double no target, and makes no mockiato or mock_derive double at all.
  mockall's generated names are found across files.
- **Fake pins near other files** (G5-10). A hand-written fake keeps no pin in
  a file that imports from another project file or has a glob from one, since
  a trait there could answer the call (a `src/tests.rs` with
  `use crate::*;` gives no echo).
- **Borrowed mocks** (G5-10). Any `&mock` handed on, of a faux, mry, mockiato
  or other double that is neither mockall's nor a hand-written fake, drops its
  value, and so does `(&mock).get()`. A hand-written fake keeps its pins when
  borrowed: they are its own code.
- **Mocks near other traits.** A mock gives no value in a file that brings in
  any trait other than the ones it implements, whatever that trait's methods
  are called: the index holds no trait's methods. A test that imports two
  mocked traits gives neither mock a value, a faux or mry struct mock gives
  none beside any imported trait, and neither does a mock in a test with
  `use mockall::*;` or an import from a crate other than the standard and
  mocking ones.
- **Frameworks that pin no value.** mockers, mock_derive and galvanic give a
  double's identity and pins only, never a `mock-config` value, and mocktopus
  gives a pin with no value: each configures through a second handle or a
  macro the worker does not trace.
- **Assertion macros outside the prelude** (G5-06). `prop_assert*!` and
  `assert_matches!` are never read as assertions, and `#[macro_use] extern
crate`, a local `mod core` or an unseen `#[path]` leaves every assertion of
  the package unread.
- **Literals near macros** (G5-03). A statement-position macro other than a
  standard one, a glob or nested `fn` in the body, and a `cfg`-gated file or
  module (`mod tests` included) leave the names they touch unknown.
- **Coarse incremental reuse** (G5-05). A test file reads every file of its
  package to know that no macro shadows a standard assertion, so an edit to
  any file of the package rescans its test files.

## Limits

- Macro bodies are not expanded. A path written as a call in a `macro_rules!`
  body (`valid_text(stringify!($name), value)`, `$crate::x::f(..)`) is a
  `possible` call from the module that defines the macro, and so is one in an
  invocation whose body is not comma-separated expressions (`vec![f(x); n]`).
  `matches!(value, pattern if guard)` is read as code. A call a macro builds
  out of pieces, or one a procedural macro generates, is invisible.
- `cfg`-gated code is walked in full, because the worker resolves no features. A
  symbol behind a disabled feature still appears.
- Generic instantiation is not resolved. A type named as a generic argument,
  in a turbofish (`size_of::<Info>()`, `Option::<Id>::default()`) or in a
  type, is a `references` target like any other type.
- A method call on a receiver whose type the source does not state produces no
  edge. The method name is recorded in the module's `unresolved_member_calls`,
  so a method by that name is only possibly dead.
- A call through a qualified self, such as `<Widget>::default()`, produces no
  edge. The parser renders that callee as the bare word `default`, which names
  no path the worker can confirm. The same holds for a call through an `Fn`
  receiver, `self()`.
- A call target's kind follows Rust's naming convention: an uppercase segment
  before the final one means a method, anything else means a function, and an
  uppercase final segment means a constructor. A crate that breaks the
  convention can produce a target that matches no declared node; inside your
  own crates that edge is dropped.
- An import name bound to two different paths in one file resolves to nothing,
  unless every `use` binding it is gated by a `cfg`, see above. So does a name
  re-exported from different paths when one of those re-exports is ungated.
- A file no `mod` declaration loads reads the files of its own directory and
  the one above it, where a `#[path]` that would load it is written; one a
  `#[path]` in a new file or a farther directory comes to load keeps the module
  its path gave it in an incremental scan until it changes itself or a full
  scan runs.
- Re-exports are resolved through the declaring file's own `use` items, its
  own declarations and rooted paths only. Glob imports are followed four
  levels deep at most.
- A type named bare that nothing in scope declares or imports (`Vec`,
  `Option`, `String`, `char`, `u8`) types no receiver, so a method called on
  one produces no edge rather than one to a made-up type of the module. A
  generic other than `Box`, `Rc` and `Arc` (`Option<T>`, `Vec<T>`,
  `Mutex<T>`) is not unwrapped, so a method called on what it holds
  (`self.items[0].run()`, `self.lock.lock().run()`) is not resolved.
- Visibility is not checked. A glob source provides every item it declares,
  private ones included, so a private item of a sibling module reached through
  its glob can win over the prelude or make a name ambiguous where Rust would
  not import it.
- A glob `use` inside a function body (`use Kind::*;` before a `match`) is not
  read, so a name it brings into scope resolves as if it were absent.
- A bare `mod foo;` declaration emits only a containment edge. The module's own
  node comes from the file that defines it.
- A `use` leaf whose parent is a type (`use crate::errors::Error::Io;`, or
  `use Error::*;`) references that type instead of importing a module.
- A `use` leaf that already names a module resolves to the module's parent:
  `use core::fmt;` emits `imports` to `core`, and `use crate::token;` emits it
  to `crate`. The declaration index records types, traits and functions rather
  than `mod` declarations, and import collection does not consult it, so every
  multi-segment leaf other than an explicit `self` is cut the same way.
- An `impl` whose target type is not declared in the project keeps its method
  nodes but drops the `contains` and `implements` edges whose source cannot be
  vouched for. That is a deliberate false negative rather than a wrong fact.
- The route recognizers cover the forms listed above. Macro expansion, runtime
  router composition and arbitrary framework wrappers are out of reach.

## Checks on the worker

The worker is checked with `cargo fmt`, `cargo clippy`, `cargo test` and the
shared scanner-conformance check. The repository's Rust integration suite drives
the real worker process over NDJSON-RPC.

## Processes it starts

`Command::new` links the function to the file the process runs, with a probable
`launches` edge to that file's module: the first path among the `.arg(...)` and
`.args([...])` literals after an interpreter (`Command::new("python3")
.arg("tools/run.py")`), or a program named by a path
(`Command::new("./target/release/tool")`). A name with no directory counts only
with a script's extension. The core resolves the path to the module of the
project file at that path, or of the one file whose path ends with it, and drops
the edge when there is none.

`env!("CARGO_BIN_EXE_tool")`, which Cargo sets for an integration test or a
benchmark to the path of the package's binary `tool`, launches that binary: a
speculative `launches` edge to each file that could be its root in the test's
package (`src/bin/tool.rs`, `src/bin/tool/main.rs`, `src/main.rs`), kept for
the ones that exist. A binary whose path a test reads from another environment
variable is not followed.
