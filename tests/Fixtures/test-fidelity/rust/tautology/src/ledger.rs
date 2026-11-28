/// A running total.
#[derive(Default)]
pub struct Ledger {
    entries: Vec<u64>,
}

impl Ledger {
    /// Adds `amount` and gives the new total.
    pub fn add(&mut self, amount: u64) -> u64 {
        self.entries.push(amount);
        self.entries.iter().sum()
    }
}
