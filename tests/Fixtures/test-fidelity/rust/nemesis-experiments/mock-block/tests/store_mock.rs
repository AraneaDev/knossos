use mockall::mock;

// `purge` was removed from Store; the mock! block still declares it.
mock! {
    pub Store {
        fn read(&self, key: &str) -> String;
        fn purge(&self) -> bool;
    }
}
