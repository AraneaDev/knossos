// Fixture test using mockall's #[automock] against the Storage trait.
use mockall::automock;

mod contracts {
    include!("../src/contracts.rs");
}

#[automock]
trait Storage {
    fn put(&self, key: &str, value: &[u8]) -> u32;
    fn get(&self, key: &str) -> Option<Vec<u8>>;
}

#[test]
fn test_storage_mock() {
    let mut mock = MockStorage::new();
    mock.expect_put().returning(|_, _| 1);
    assert_eq!(mock.put("k", b"v"), 1);
}
