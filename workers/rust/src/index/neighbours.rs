//! Which files the first pass finds compiled as part of another one, or by
//! none: the files `include!` pulls in, and the orphans no `mod` loads.

use std::collections::{BTreeMap, BTreeSet};

use super::Pending;
use crate::layout::{Layout, ModDeclaration};

/// Each project file an `include!("path")` of another file pulls in, with
/// the files that include it and whether every one of them does so only for
/// the tests (under `#[cfg(test)]`). The path is relative to the including
/// file's directory.
pub(super) fn included_files(
    pending: &[Pending],
    mods: &BTreeMap<String, Vec<ModDeclaration>>,
) -> BTreeMap<String, (Vec<String>, bool)> {
    let mut included: BTreeMap<String, (Vec<String>, bool)> = BTreeMap::new();
    for entry in pending {
        let directory = entry.relative.rsplit_once('/').map_or("", |(dir, _)| dir);
        for (written, test) in &entry.includes {
            if written.starts_with('/') || written.contains('\\') {
                continue;
            }
            let mut segments: Vec<&str> = directory.split('/').filter(|s| !s.is_empty()).collect();
            let mut escaped = false;
            for part in written.split('/') {
                match part {
                    "" | "." => {}
                    ".." => escaped |= segments.pop().is_none(),
                    part => segments.push(part),
                }
            }
            let target = segments.join("/");
            if escaped || target == entry.relative || !mods.contains_key(&target) {
                continue;
            }
            let (includers, all_test) = included.entry(target).or_insert((Vec::new(), true));
            includers.push(entry.relative.clone());
            *all_test &= *test;
        }
    }

    included
}

/// Each project file no `mod` declaration loads (and no crate root), with
/// the project's other files in its own directory and the one above it: the
/// files a `#[path]` declaration that loads it is most likely written in,
/// since such a path is relative to the declaring file's directory. An edit
/// to one of them may place the file in a module, so the file reads them.
pub(super) fn orphan_neighbours(
    layout: &Layout,
    mods: &BTreeMap<String, Vec<ModDeclaration>>,
) -> BTreeMap<String, Vec<String>> {
    let mut loaded = BTreeSet::new();
    for (file, list) in mods {
        for declaration in list {
            if let Some(child) = layout.child_file(
                file,
                &declaration.inline,
                &declaration.name,
                declaration.path.as_deref(),
            ) {
                loaded.insert(child);
            }
        }
    }
    let directory = |file: &str| file.rsplit_once('/').map_or("", |(dir, _)| dir).to_owned();
    let mut orphans = BTreeMap::new();
    for file in mods.keys() {
        if loaded.contains(file) || layout.is_crate_root(file) {
            continue;
        }
        let own = directory(file);
        let parent = directory(&own);
        let neighbours: Vec<String> = mods
            .keys()
            .filter(|other| *other != file)
            .filter(|other| {
                let theirs = directory(other);
                theirs == own || (!own.is_empty() && theirs == parent)
            })
            .cloned()
            .collect();
        orphans.insert(file.clone(), neighbours);
    }

    orphans
}
