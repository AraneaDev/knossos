//! Momus test/integration/mcp.test.ts:1074: a mockall mock of a trait.
pub struct Record;

pub trait Repo {
    fn find(&self, id: u32) -> u32;
    fn save(&self, value: u32) -> bool;
    fn record(&self) -> Record;
}
