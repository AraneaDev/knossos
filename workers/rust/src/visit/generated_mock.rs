//! The mock type a generator makes (see the `generated` module), apart from
//! the reading of the generators so the declaration index can hold one
//! without depending on the walk.

/// The pattern of a mock `#[automock]` generates.
pub const AUTOMOCK: &str = "mockall::automock";

/// The pattern of a mock `mock!` generates.
pub const MOCK_MACRO: &str = "mockall::mock";

/// One mock type a generator makes.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct GeneratedMock {
    /// The canonical path of what the mock stands in for, when it is known:
    /// the trait, or the `impl` block's self type.
    pub target: Option<String>,
    /// The node kind of `target`: `interface` for a trait, `class` for a
    /// type.
    pub target_kind: &'static str,
    /// [`AUTOMOCK`] or [`MOCK_MACRO`].
    pub pattern: &'static str,
    /// Whether a `cfg` that requires `test` gates the generator (its own,
    /// a `cfg_attr(test, automock)`, or an enclosing inline module's): the
    /// mock then exists only in its own crate's test build.
    pub test_gated: bool,
    /// Whether the mock is an `#[automock] mod`'s, whose functions give
    /// static contexts only.
    pub contexts_only: bool,
    /// The associated functions the mocked type declares among `new` and
    /// `default`: mockall mocks such a function as a static method, so
    /// `MockS::new()` returns whatever its context's expectation does and
    /// makes no fresh mock.
    pub mocked_constructors: Vec<String>,
    /// The project-relative path of the generating file, whose crate a
    /// test gate keeps the mock to.
    pub file: String,
    /// The canonical path of each trait the mock implements, `None` for
    /// one the generating file's own imports cannot place: none for a
    /// mock of inherent methods alone.
    pub traits: Vec<Option<String>>,
}
