use mockall::automock;

#[automock]
trait Repository {
    fn find(&self, key: &str) -> Option<String>;
    fn save(&self, key: &str, value: &str) -> bool;
}

#[test]
fn repository_contract_experiment() {
    let mut repository = MockRepository::new();
    repository.expect_find().returning(|_| None);
    assert!(repository.find("missing").is_none());
}
