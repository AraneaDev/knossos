use crate::repo::Repo;

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn healthy() {
        let mut m = MockRepo::new();
        m.expect_find().returning(|id| id + 1);
        m.expect_save().returning(|_| true);
    }
}
