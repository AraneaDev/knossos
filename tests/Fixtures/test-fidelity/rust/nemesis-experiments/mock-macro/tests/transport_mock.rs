#[test]
fn mock_macro_experiment() {
    let _payload = b"data";
    assert!(true);
}

mock! {
    Transport {
        fn send(&self, payload: &[u8]) -> bool;
    }
}
