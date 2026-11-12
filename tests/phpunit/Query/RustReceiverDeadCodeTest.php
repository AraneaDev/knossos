<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\Group;

/**
 * A Rust method whose callers reach it through a receiver the scan could not
 * type reads as dead code. On one 675-candidate workspace, 661 of the
 * `possible` candidates had a product caller in the source: a binding of a
 * call into another file through `?`, a `match` arm's `Some(p)`, an enum
 * variant's field, a closure over `values.iter()`, a generic bounded by a
 * project trait. And an inherent method of a type that implements `Drop` or
 * `Debug` was demoted because of that trait, which never dispatches to it.
 */
final class RustReceiverDeadCodeTest extends KnossosTestCase
{
    #[Group('query')]
    public function testMethodsCalledThroughInferredReceiversAreNotCandidates(): void
    {
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $root = sys_get_temp_dir() . '/knossos-stale-rust-receivers-' . bin2hex(random_bytes(6));
        mkdir($root . '/src/report', 0o700, true);
        file_put_contents($root . '/Cargo.toml', "[package]\nname = \"receivers\"\nversion = \"0.1.0\"\n");
        file_put_contents($root . '/src/main.rs', <<<'RUST'
            mod report;
            mod model;
            mod duplex;
            mod native;

            fn main() {
                let _ = native::load("root", None);
                let _ = model::check(&[], None);
                duplex::run_duplex(&mut duplex::Session);
            }
            RUST);
        file_put_contents($root . '/src/native.rs', <<<'RUST'
            use crate::report;

            pub fn load(root: &str, parent: Option<&report::ReportStore>) -> anyhow::Result<()> {
                let store = report::open_existing(root)?;
                store.load_selected_report()?;
                let child = match parent {
                    Some(p) => p.create_child()?,
                    None => report::open_existing(root)?,
                };
                child.intent_store();
                Ok(())
            }
            RUST);
        file_put_contents($root . '/src/report/mod.rs', "mod store;\npub use store::{open_existing, ReportStore};\n");
        file_put_contents($root . '/src/report/store.rs', <<<'RUST'
            use anyhow::Result;

            #[derive(Debug)]
            pub struct ReportStore;

            pub fn open_existing(_: &str) -> Result<ReportStore> {
                Ok(ReportStore)
            }

            impl ReportStore {
                pub fn load_selected_report(&self) -> Result<()> { Ok(()) }
                pub fn create_child(&self) -> Result<ReportStore> { Ok(ReportStore) }
                pub fn intent_store(&self) {}
                pub fn never_called_anywhere(&self) {}
            }

            impl Drop for ReportStore {
                fn drop(&mut self) {}
            }
            RUST);
        file_put_contents($root . '/src/model.rs', <<<'RUST'
            pub struct Value;

            impl Value {
                pub fn valid(&self) -> bool { true }
                pub fn equals(&self) -> bool { true }
            }

            pub enum Observation {
                Equals { actual: Value },
                Many { values: Vec<Value> },
            }

            pub fn check(observations: &[Observation], maybe: Option<Value>) -> bool {
                for observation in observations {
                    match observation {
                        Observation::Equals { actual } if !actual.valid() => return false,
                        Observation::Many { values } => {
                            if values.iter().any(|v| !v.equals()) {
                                return false;
                            }
                        }
                        _ => {}
                    }
                }
                maybe.is_some()
            }
            RUST);
        file_put_contents($root . '/src/duplex.rs', <<<'RUST'
            pub trait DuplexDriver {
                fn pending_write(&self) -> &[u8];
                fn written(&mut self, n: usize);
            }

            pub struct Session;

            impl DuplexDriver for Session {
                fn pending_write(&self) -> &[u8] { &[] }
                fn written(&mut self, _: usize) {}
            }

            pub fn run_duplex<D: DuplexDriver>(driver: &mut D) {
                let n = driver.pending_write().len();
                driver.written(n);
            }
            RUST);
        $pdo = $this->freshTestDatabase();
        try {
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
            $data = ArchitectureQueryService::forDatabase($pdo)->services()->healthQueries->architectureHealth($projectId, limit: 100)->data;
            $byName = [];
            foreach ($data['dead_code_candidates'] as $candidate) {
                $byName[$candidate['component']['canonical_name']] = $candidate;
            }

            foreach ([
                'crate::report::store::ReportStore::load_selected_report',
                'crate::report::store::ReportStore::create_child',
                'crate::report::store::ReportStore::intent_store',
                'crate::model::Value::valid',
                'crate::model::Value::equals',
                'crate::duplex::Session::<impl DuplexDriver>::pending_write',
                'crate::duplex::Session::<impl DuplexDriver>::written',
            ] as $called) {
                assertArrayNotHasKey($called, $byName, $called . ' has a product caller');
            }
            // Neither `Debug` nor `Drop` reaches an inherent method, so the
            // uncalled one is probably dead, not possibly.
            $orphan = 'crate::report::store::ReportStore::never_called_anywhere';
            assertArrayHasKey($orphan, $byName);
            assertSame('probable', $byName[$orphan]['confidence'], $byName[$orphan]['reason']);
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }
}
