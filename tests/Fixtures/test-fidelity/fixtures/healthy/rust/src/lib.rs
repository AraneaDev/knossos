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
