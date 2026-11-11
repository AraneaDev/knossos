//! The first pass of a scan: reading every Rust file of the project and
//! building the crate-wide declaration index from it.
//!
//! Requested files are parsed and kept for the walk; every other file is
//! read and hashed, and parsed only when this worker has not indexed those
//! bytes before, so later requests of a scan stay as cheap as reading.

use std::cell::RefCell;
use std::collections::{BTreeMap, BTreeSet, HashMap};
use std::path::Path;

use serde_json::Value;

use crate::facts::Facts;
use crate::heartbeat::Heartbeat;
use crate::layout::{Layout, ModDeclaration};
use crate::nesting::{nesting_beyond, MAX_NESTING};
use crate::params::ScanRequest;
use crate::protocol::Contribution;
use crate::reads::{read_safely, record_read};
use crate::source_hash::sha256_hex;
use crate::visit::{Declarations, ModuleGates};

/// What one file gives the declaration index.
#[derive(Clone, Debug)]
struct FileIndex {
    /// Every path the file declares, see [`crate::visit::declaration_paths`].
    declarations: BTreeSet<String>,
    /// The ones a `cfg` other than `test` gates, see
    /// [`crate::visit::gated_declaration_paths`].
    gated: BTreeSet<String>,
    /// Every out-of-line `#[cfg(test)] mod name;` the file declares.
    test_modules: crate::visit::ModuleGates,
    /// Every `mod name;` the file declares at a path other than the module
    /// it loads, see [`crate::visit::declared_renames`].
    renames: BTreeMap<String, Option<String>>,
    /// Every name the file's visible `use` items re-export, see
    /// [`crate::visit::index_facts`].
    exports: crate::visit::ExportedNames,
    /// The field types of every struct the file declares, see
    /// [`crate::visit::index_facts`].
    fields: crate::visit::StructFields,
    /// The type every module-level alias of the file names, see
    /// [`crate::visit::index_facts`].
    aliases: crate::visit::TypeAliases,
    /// The `cfg` alternatives of every name the file re-exports apart, see
    /// [`crate::visit::ExportAlternatives`].
    export_alternatives: crate::visit::ExportAlternatives,
    /// The modules every module of the file glob-imports from, see
    /// [`crate::visit::GlobImports`].
    globs: crate::visit::GlobImports,
    /// The names only a private `use` of the file binds.
    private_exports: BTreeSet<String>,
}

impl FileIndex {
    /// The index entries of `relative`, placed in `module`. A file the index
    /// does not answer for (see [`Layout::is_indexed`]) declares nothing to
    /// it, no names and no renamed modules, though its test modules still
    /// count.
    fn of(relative: &str, module: &str, items: &[syn::Item], layout: &Layout) -> Self {
        let mut test_modules = crate::visit::ModuleGates::new();
        crate::visit::collect_test_modules(relative, module, items, layout, &mut test_modules);
        let indexed = layout.is_indexed(relative, module);
        let crate::visit::IndexFacts {
            impl_methods,
            gated_impl_methods,
            private_exports,
            exports,
            fields,
            aliases,
            export_alternatives,
            globs,
        } = if indexed {
            crate::visit::index_facts(relative, module, items, layout)
        } else {
            Default::default()
        };
        let (mut declarations, mut gated) = if indexed {
            crate::visit::indexed_item_paths(module, items)
        } else {
            Default::default()
        };
        declarations.extend(impl_methods);
        gated.extend(gated_impl_methods);
        Self {
            declarations,
            gated,
            test_modules,
            renames: if indexed {
                crate::visit::declared_renames(relative, module, items, layout)
            } else {
                BTreeMap::new()
            },
            exports,
            fields,
            aliases,
            export_alternatives,
            globs,
            private_exports,
        }
    }
}

/// One file awaiting its walk: parsed successfully, or already reduced to a
/// diagnostic-only contribution (unreadable, oversized, escaping, or
/// unparsable — each failure costs only its own file).
pub(crate) enum Prepared {
    /// Read, validated, and parsed; ready to walk.
    Parsed {
        /// Project-relative path.
        relative: String,
        /// The parsed syntax tree.
        parsed: syn::File,
        /// SHA-256 hex of the raw bytes `parsed` came from.
        content_hash: String,
    },
    /// A failed file, reduced to its final (diagnostic-only) contribution.
    Err {
        /// The diagnostic-only contribution itself.
        contribution: Contribution,
        /// Whether the filesystem refused the read (gone, not a regular file,
        /// over the byte cap, or resolving outside the root), as opposed to
        /// bytes that were read and failed to parse.
        read_failed: bool,
    },
}

/// What the memo keeps of one file's bytes: its bodiless `mod` declarations,
/// which follow from the bytes alone, and its index entries under the
/// layout the memo was built under; `None` entries for bytes that do not
/// parse.
#[derive(Clone, Debug)]
struct Memoized {
    /// See [`crate::visit::mod_declarations`].
    mods: Vec<ModDeclaration>,
    /// See [`FileIndex`].
    index: Option<FileIndex>,
}

/// The [`Memoized`] entries of every file the last request read, by the
/// file's path and the hash of its bytes. With the [`Layout`] they were
/// collected under, both decide the entries, so a hit is what parsing would
/// give.
type IndexMemo = HashMap<(String, String), Memoized>;

thread_local! {
    /// The worker lives across the requests of a scan, and each request
    /// indexes the whole project: parsing only bytes it has not seen keeps
    /// a large workspace's later batches as cheap as reading it. The memo is
    /// kept for the layout it was built under; under another, only the
    /// `mod` declarations it holds are reused.
    static INDEX_MEMO: RefCell<(Layout, IndexMemo)> = RefCell::new((Layout::default(), HashMap::new()));
}

/// What the first pass hands the walk.
pub(crate) struct ProjectIndex {
    /// Each requested file, parsed or reduced to its diagnostic, in order.
    pub(crate) prepared: Vec<Prepared>,
    /// The crate-wide declaration index, see [`Declarations`].
    pub(crate) declarations: Declarations,
    /// Every out-of-line `#[cfg(..)] mod name;` any file declared.
    pub(crate) test_modules: ModuleGates,
    /// The request's layout with every `#[path]`-loaded file placed in the
    /// module its declaration names (see [`Layout::place_path_modules`]),
    /// which the walk and the reads go by.
    pub(crate) layout: Layout,
}

/// One project file between reading it and indexing it.
struct Pending {
    /// Project-relative path.
    relative: String,
    /// The hash of the bytes read, `None` when the read was refused.
    hash: Option<String>,
    /// Its `mod` declarations, see [`crate::visit::mod_declarations`].
    mods: Vec<ModDeclaration>,
    /// Its index entries as the memo holds them, under the memo's layout.
    memoized: Option<Option<FileIndex>>,
    /// Its index entries under the request's layout before any file is
    /// placed, for a context file parsed here.
    tentative: Option<Option<FileIndex>>,
    /// The position of its [`Prepared`] for a requested file.
    requested: Option<usize>,
}

/// Pass 1: read every Rust file of the project and index it, returning the
/// requested files ready to walk with the declaration index, the modules
/// every file's `mod` declarations gate, and the layout with each
/// `#[path]`-loaded file placed. Without `source_files` the project is the
/// batch, as it always was. An unrequested file's tree is dropped once
/// indexed, so the whole project is never held parsed at once.
///
/// Where a file sits in the module tree follows from the `mod` declarations
/// of other files when a `#[path]` is involved, so the files are read and
/// their declarations collected first, the files placed, and only then is
/// each indexed in its module. A context file parsed here is indexed at
/// once in the module its path gives it, and parsed again only when the
/// placement reaches it: it is placed, a `#[path]` loads it, or one of its
/// own declarations has a `#[path]`.
pub(crate) fn index_project(
    request: &ScanRequest,
    layout: &Layout,
    input_hashes: &mut BTreeMap<String, Option<String>>,
    heartbeat: &mut Heartbeat,
    emit: &mut dyn FnMut(&Value),
) -> ProjectIndex {
    let root = &request.root;
    let max_file_bytes = request.max_file_bytes;
    let requested: BTreeSet<&str> = request.relatives.iter().map(String::as_str).collect();
    let project: BTreeSet<&str> = request
        .source_files
        .iter()
        .map(String::as_str)
        .chain(requested.iter().copied())
        .collect();
    let mut prepared: Vec<Prepared> = Vec::with_capacity(request.relatives.len());
    let (built_under, mut memo) = INDEX_MEMO.with(|memo| std::mem::take(&mut *memo.borrow_mut()));
    let mut pending: Vec<Pending> = Vec::with_capacity(project.len());
    for relative in project {
        heartbeat.beat(emit);
        let entry = if requested.contains(relative) {
            let item = prepare_one(root, relative, max_file_bytes);
            let mods = match &item {
                Prepared::Parsed { parsed, .. } => crate::visit::mod_declarations(&parsed.items),
                Prepared::Err { .. } => Vec::new(),
            };
            let hash = read_value(&item);
            prepared.push(item);
            Pending {
                relative: relative.to_owned(),
                hash,
                mods,
                memoized: None,
                tentative: None,
                requested: Some(prepared.len() - 1),
            }
        } else {
            read_context_file(root, relative, max_file_bytes, layout, &mut memo)
        };
        record_read(input_hashes, relative, entry.hash.clone());
        pending.push(entry);
    }
    let mods: BTreeMap<String, Vec<ModDeclaration>> = pending
        .iter()
        .filter(|entry| entry.hash.is_some())
        .map(|entry| (entry.relative.clone(), entry.mods.clone()))
        .collect();
    let placed = layout.place_path_modules(&mods);
    let memo_valid = built_under == placed;
    let mut indexed: Vec<(String, String, Option<FileIndex>)> = Vec::with_capacity(pending.len());
    let mut kept: IndexMemo = HashMap::new();
    for entry in pending {
        heartbeat.beat(emit);
        let reached = placed.placements.contains_key(&entry.relative)
            || placed.path_loaded.contains(&entry.relative)
            || entry
                .mods
                .iter()
                .any(|declaration| declaration.path.is_some());
        let module = placed.module_of(&entry.relative);
        let index = match (entry.requested, entry.memoized, entry.tentative) {
            (Some(position), _, _) => match &prepared[position] {
                Prepared::Parsed { parsed, .. } => Some(FileIndex::of(
                    &entry.relative,
                    &module,
                    &parsed.items,
                    &placed,
                )),
                Prepared::Err { .. } => None,
            },
            (None, Some(index), _) if memo_valid => index,
            (None, _, Some(index)) if !reached => index,
            (None, _, _) => match entry.hash {
                Some(_) => reindex(root, &entry.relative, &module, max_file_bytes, &placed),
                None => None,
            },
        };
        if let Some(hash) = &entry.hash {
            kept.insert(
                (entry.relative.clone(), hash.clone()),
                Memoized {
                    mods: entry.mods,
                    index: index.clone(),
                },
            );
        }
        indexed.push((entry.relative, module, index));
    }
    let mut test_modules = ModuleGates::new();
    for (_, _, index) in &indexed {
        if let Some(index) = index {
            test_modules.extend(&index.test_modules);
        }
    }
    let mut declarations = Declarations::new();
    for (relative, module, index) in &indexed {
        if let Some(index) = index {
            let file_gated = test_modules.gate_of_file(relative, module).is_some();
            declarations.absorb(index, file_gated);
        }
    }
    // Only what this request indexed is kept, so the memo follows the
    // project rather than every version of it this process has seen.
    INDEX_MEMO.with(|memo| *memo.borrow_mut() = (placed.clone(), kept));

    ProjectIndex {
        prepared,
        declarations,
        test_modules,
        layout: placed,
    }
}

/// An unrequested file read and hashed, with its `mod` declarations and its
/// index entries in the module its path gives it: from the memo when this
/// worker has seen those bytes, else parsed here. A refused read leaves
/// nothing to index.
fn read_context_file(
    root: &Path,
    relative: &str,
    max_file_bytes: u64,
    layout: &Layout,
    memo: &mut IndexMemo,
) -> Pending {
    let mut entry = Pending {
        relative: relative.to_owned(),
        hash: None,
        mods: Vec::new(),
        memoized: None,
        tentative: None,
        requested: None,
    };
    let Ok(bytes) = read_safely(root, relative, max_file_bytes) else {
        return entry;
    };
    let hash = sha256_hex(&bytes);
    match memo.remove(&(relative.to_owned(), hash.clone())) {
        Some(memoized) => {
            entry.mods = memoized.mods;
            entry.memoized = Some(memoized.index);
        }
        None => {
            let parsed = parse_source(&bytes).ok();
            entry.mods = parsed
                .as_ref()
                .map(|parsed| crate::visit::mod_declarations(&parsed.items))
                .unwrap_or_default();
            entry.tentative = Some(parsed.map(|parsed| {
                FileIndex::of(relative, &layout.module_of(relative), &parsed.items, layout)
            }));
        }
    }
    entry.hash = Some(hash);

    entry
}

/// A context file read and parsed again, to be indexed in the module the
/// placement of `#[path]`-loaded files gives it.
fn reindex(
    root: &Path,
    relative: &str,
    module: &str,
    max_file_bytes: u64,
    layout: &Layout,
) -> Option<FileIndex> {
    let bytes = read_safely(root, relative, max_file_bytes).ok()?;
    let parsed = parse_source(&bytes).ok()?;
    Some(FileIndex::of(relative, module, &parsed.items, layout))
}

impl Declarations {
    /// Adds what one file declares, renames, exports, and aliases, and its
    /// struct fields; `file_gated` when a `cfg` on the `mod` declaration
    /// that loads the file gates all of it.
    fn absorb(&mut self, index: &FileIndex, file_gated: bool) {
        self.add_gated_file(&index.declarations, &index.gated, file_gated);
        self.add_renames(&index.renames);
        self.add_exports(&index.exports);
        self.add_fields(&index.fields);
        self.add_aliases(&index.aliases);
        self.add_export_alternatives(&index.export_alternatives);
        self.add_globs(&index.globs);
        self.add_private_exports(&index.private_exports);
    }
}

/// What one prepared file puts in `input_hashes`: the hash of the bytes read,
/// or `None` when the filesystem refused the read. A file whose read failed is
/// not what discovery hashed right now, and a requested one's contribution
/// stands in for its facts empty: null makes the core fail the scan for a
/// discovered path rather than keep a graph without them.
fn read_value(item: &Prepared) -> Option<String> {
    match item {
        Prepared::Parsed { content_hash, .. } => Some(content_hash.clone()),
        Prepared::Err {
            read_failed: true, ..
        } => None,
        Prepared::Err { contribution, .. } => contribution.content_hash.clone(),
    }
}

/// Read, validate, and parse one file into a [`Prepared`].
///
/// Every failure is per file. Aborting the request would discard the facts
/// every other file in the batch contributes, so one unreadable or
/// unparsable file costs only its own contribution.
fn prepare_one(root: &Path, relative: &str, max_file_bytes: u64) -> Prepared {
    let mut facts = Facts::new(relative);
    let bytes = match read_safely(root, relative, max_file_bytes) {
        Ok(bytes) => bytes,
        Err(message) => {
            facts.diagnostic("error", "RS_UNSCANNABLE_FILE", &message, 1);
            return Prepared::Err {
                contribution: facts.finish(),
                read_failed: true,
            };
        }
    };
    let content_hash = sha256_hex(&bytes);
    facts.set_content_hash(content_hash.clone());
    match parse_source(&bytes) {
        Ok(parsed) => Prepared::Parsed {
            relative: relative.to_owned(),
            parsed,
            content_hash,
        },
        Err((code, message, line)) => {
            facts.diagnostic("error", code, &message, line);
            Prepared::Err {
                contribution: facts.finish(),
                read_failed: false,
            }
        }
    }
}

/// Parse a file's bytes, or the diagnostic code, message and line saying why
/// they do not parse: not UTF-8, nested past [`MAX_NESTING`], or not Rust.
fn parse_source(bytes: &[u8]) -> Result<syn::File, (&'static str, String, usize)> {
    let source =
        std::str::from_utf8(bytes).map_err(|e| ("RS_UNSCANNABLE_FILE", e.to_string(), 1))?;
    if let Some(depth) = nesting_beyond(source, MAX_NESTING) {
        return Err((
            "RS_TOO_DEEP",
            format!("Delimiters nest {depth} levels deep, past the limit of {MAX_NESTING}."),
            1,
        ));
    }
    syn::parse_file(source).map_err(|error| {
        let line = error.span().start().line.max(1);
        ("RS_SYNTAX_ERROR", error.to_string(), line)
    })
}
