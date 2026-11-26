//! Receivers, visibility and async, and what a mock cannot know.
use serde::Deserialize;

#[derive(Deserialize)]
pub struct Payload {
    pub id: u32,
    pub label: String,
}

pub trait Store {
    fn get(&self, key: &str) -> Option<Payload>;
    fn put(&mut self, key: String, value: Payload);
    fn into_keys(self) -> Vec<String>;
    fn boxed(self: Box<Self>) -> usize;
    fn open(path: &str) -> Self;
    async fn sync(&self) -> Result<(), String>;
    unsafe fn raw(&self) -> *const u8;
    fn convert<T: From<u32>>(&self, value: u32) -> T;
    fn payload(&self) -> Payload;
}

pub trait Named: std::fmt::Display {
    fn name(&self) -> String;
}

pub trait Audited: crate::repo::Repo {
    fn audit(&self) -> bool;
}

pub struct Engine;

impl Engine {
    pub fn new() -> Self {
        Engine
    }

    pub fn start(&mut self) -> bool {
        true
    }

    pub(crate) fn tune(&self) {}

    fn hidden(&self) {}
}

#[derive(Deserialize)]
pub enum Mode {
    Fast,
    Safe,
}

pub fn helper() {}

pub trait Base {
    fn base(&self) -> u8;
}

impl Base for Engine {
    fn base(&self) -> u8 {
        0
    }
}

pub trait Keyed<T> {
    fn get(&self, id: u64) -> Option<T>;
    fn put(&mut self, value: T);
}

pub struct KeyStore;

impl Keyed<String> for KeyStore {
    fn get(&self, _id: u64) -> Option<String> {
        None
    }
    fn put(&mut self, _value: String) {}
}

pub trait WithAssoc {
    type Output;
    const LIMIT: usize;
    fn produce(&self) -> Self::Output;
    fn limit(&self) -> usize;
}

pub trait Borrow {
    fn plain(&self) -> &str;
}

pub enum Plain {
    A,
    B,
}

pub union Bits {
    a: u32,
    b: f32,
}
