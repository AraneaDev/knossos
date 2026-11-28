// Mirrors mockall's automock_generic_future_with_where_clause.rs / automock_qself.rs:
// generic-dependent return types — a bare type param (`V`) and a qself projection
// (`<T as OutputTrait>::Output`) — are unprovable statically, so DRIFT-003 must stay
// quiet (row 31 in docs/11: mockall dogfood 2 DRIFT-003 false positives → 0).
pub trait MyTrait {
    fn myfunc<V>(&self) -> V;
}

pub struct Foo<T, V>(T, V);
#[automock]
impl<T, V> MyTrait for Foo<T, V> {
    fn myfunc<V>(&self) -> V {
        unimplemented!()
    }
}

pub trait OutputTrait {
    type Output;
}

pub struct SendFoo {}
impl OutputTrait for SendFoo {
    type Output = u32;
}

pub struct A {}
#[automock]
impl A {
    pub fn bar<T: OutputTrait + 'static>(&self, _t: T) -> <T as OutputTrait>::Output {
        unimplemented!()
    }
}

#[test]
fn generic_param_return() {
    let mut mock = MockFoo::<u32, u32>::new();
    mock.expect_myfunc().return_const(42u32);
    assert_eq!(42u32, mock.myfunc());
}

#[test]
fn qself_projection_return() {
    let mut mock = MockA::new();
    mock.expect_bar::<SendFoo>().return_const(42u32);
    mock.bar(SendFoo {});
}
