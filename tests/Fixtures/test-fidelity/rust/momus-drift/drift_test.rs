use crate::repo::Repo;

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn drift() {
        let mut m = MockRepo::new();
        m.expect_save2().return_const(true);   // DRIFT-001: save2 is not on Repo
        m.expect_find().return_const("nope");  // DRIFT-003: &str not assignable to u32
        m.expect_record().return_const(42);    // DRIFT-003: 42 is not a Record
    }
}
