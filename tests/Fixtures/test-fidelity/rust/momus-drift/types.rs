pub trait Widget {
    fn label(&self) -> String;
    fn count(&self) -> Option<u32>;
    fn items(&self) -> Result<Vec<String>, String>;
    fn active(&self) -> bool;
    fn initial(&self) -> char;
    fn pair(&self) -> (u32, bool);
    fn never(&self) -> !;
}