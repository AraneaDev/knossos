//! A type whose equality is the code under test.

/// A version that equals another of the same major number.
#[derive(Debug, Clone, Copy)]
pub struct Version {
    /// The major number.
    pub major: u32,
}

impl Version {
    /// A version of `major`.
    pub fn new(major: u32) -> Self {
        Version { major }
    }
}

impl PartialEq for Version {
    fn eq(&self, other: &Self) -> bool {
        self.major == other.major
    }
}
