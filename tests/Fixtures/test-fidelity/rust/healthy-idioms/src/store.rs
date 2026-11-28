//! A store the tests mock with faux.

/// A key-value store.
#[cfg_attr(test, faux::create)]
pub struct Store {
    size: u32,
}

#[cfg_attr(test, faux::methods)]
impl Store {
    /// How many entries it holds.
    pub fn size(&self) -> u32 {
        self.size
    }
}

/// Whether the store is empty.
pub fn is_empty(store: &Store) -> bool {
    store.size() == 0
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_faux_mock_with_a_closure_then() {
        let mut store = Store::faux();
        faux::when!(store.size).then(|_| 3);
        assert_eq!(store.size(), 3);
    }

    #[test]
    fn a_faux_mock_handed_to_production() {
        let mut store = Store::faux();
        faux::when!(store.size).then_return(0);
        assert!(is_empty(&store));
    }
}
