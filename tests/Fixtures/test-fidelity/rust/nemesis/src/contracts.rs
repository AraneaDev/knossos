/// Fixture trait for mockall scenarios.
pub trait Storage {
    fn put(&self, key: &str, value: &[u8]) -> u32;
    fn get(&self, key: &str) -> Option<Vec<u8>>;
}
