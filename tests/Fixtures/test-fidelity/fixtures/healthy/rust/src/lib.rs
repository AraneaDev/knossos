use rust_decimal::Decimal;
use serde::Deserialize;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Deserialize)]
pub enum Dock {
    North,
    South,
}

/// A value that may be missing: serde reads a missing key as `None`
/// through the alias too (G6-08e).
pub type Maybe<T> = Option<T>;

#[derive(Debug, Deserialize)]
pub struct Pallet {
    pub id: u64,
    pub dock: Dock,
    pub note: Option<String>,
    pub label: Maybe<String>,
    #[serde(default)]
    pub layers: u8,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Tracking {
    pub tracking_code: String,
}

#[derive(Debug, Deserialize)]
pub struct Carton {
    #[serde(rename = "cartonId")]
    pub carton_id: u32,
}

#[derive(Debug, Deserialize)]
pub struct Bundle {
    pub id: u32,
    #[serde(flatten)]
    pub extra: std::collections::HashMap<String, String>,
}

#[derive(Debug, Deserialize)]
pub struct Quote {
    pub price: Decimal,
    pub quantity: u32,
}
