// Production types for supertrait_test.rs: `trait Derived : Base` inherits `add` from `Base`.
pub trait Base {
    fn add(&self, x: i32) -> usize;
}

pub trait Derived : Base {
    fn sub(&self, x: i32) -> usize;
}
