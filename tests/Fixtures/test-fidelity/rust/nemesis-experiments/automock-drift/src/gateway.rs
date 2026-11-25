use mockall::automock;

/// The trait lives in production code, which is where mockall is normally
/// used and the only arrangement in which a mock can go stale.
#[automock]
pub trait Gateway {
    fn send(&self, message: &str) -> bool;
    fn status(&self) -> u32;
}
