use serde::Deserialize;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Deserialize)]
pub enum Dock {
    North,
    South,
}

#[derive(Debug, Deserialize)]
pub struct Pallet {
    pub id: u64,
    pub dock: Dock,
    pub note: Option<String>,
    #[serde(default)]
    pub layers: u8,
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Tracking {
    pub tracking_code: String,
}
