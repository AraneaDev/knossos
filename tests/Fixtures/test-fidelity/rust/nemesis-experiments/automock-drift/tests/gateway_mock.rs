use mockall::predicate::eq;

#[test]
fn stale_method_experiment() {
    let mut gateway = MockGateway::new();
    // `dispatch` was renamed to `send`.
    gateway.expect_dispatch().returning(|_| true);
}

#[test]
fn stale_arity_experiment() {
    let mut gateway = MockGateway::new();
    gateway
        .expect_send()
        .with(eq("a"), eq("b"))
        .returning(|_, _| true);
}
