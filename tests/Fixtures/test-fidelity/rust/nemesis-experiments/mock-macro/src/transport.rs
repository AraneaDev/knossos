pub trait Transport {
    fn send(&self, payload: &[u8]) -> bool;
}
