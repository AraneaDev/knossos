pub trait Store {
    fn read(&self, key: &str) -> String;
}
