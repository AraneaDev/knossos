pub trait Clock {
    fn now(&self) -> u64;
}
