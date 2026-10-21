// The limits a test sends with a scan request, as the core always sends some:
// the defaults a project without its own limits is scanned under.
export const SCAN_LIMITS = { max_files: 100_000, max_file_bytes: 2_000_000 };
