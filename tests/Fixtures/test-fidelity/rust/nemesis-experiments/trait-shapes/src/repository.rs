pub trait Repository {
    fn find(&self, key: &str) -> Option<String>;
    fn save(&self, key: &str, value: &str) -> bool;
}
