//! A type whose worker sends no field list: it derives no `Deserialize`.

/// An order, written by hand.
pub struct Order {
    /// Its id.
    pub id: u32,
    /// Its label.
    pub label: String,
}
