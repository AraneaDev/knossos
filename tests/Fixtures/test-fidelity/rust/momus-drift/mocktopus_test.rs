pub fn global_fetch() -> u32 {
    42
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_mocktopus() {
        unsafe {
            global_fetch.mock_safe(|_| MockResult::Return("nope")); // DRIFT-003
        }
    }
}
