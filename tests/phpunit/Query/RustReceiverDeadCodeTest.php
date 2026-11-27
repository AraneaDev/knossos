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
        $root = self::scratchPath('knossos-stale-rust-receivers');
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

    /**
     * G6-08h: a private Rust method is no member a trait's shape can match
     * (Rust traits are nominal, and the worker marks it `visibility:
     * private`), so an uncalled one stays probably dead though a trait's
     * called method shares its name; and a test-gated mock of its type that
     * stubs it is no VISIBILITY_BREACH, since the crate's own tests see it.
     */
    #[Group('query')]
    public function testAnUncalledPrivateMethodSharingATraitMethodsNameIsProbablyDead(): void
    {
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $root = self::scratchPath('knossos-stale-rust-private');
        mkdir($root . '/src', 0o700, true);
        file_put_contents($root . '/Cargo.toml', "[package]\nname = \"engines\"\nversion = \"0.1.0\"\n\n[dev-dependencies]\nmockall = \"0.13\"\n");
        file_put_contents($root . '/src/main.rs', "mod engine;\nmod runner;\n\nfn main() {\n    runner::drive(&runner::Motor);\n    engine::Engine.run();\n}\n");
        file_put_contents($root . '/src/runner.rs', "pub trait Runner {\n    fn start(&self);\n}\n\npub struct Motor;\n\nimpl Runner for Motor {\n    fn start(&self) {}\n}\n\npub fn drive(runner: &dyn Runner) {\n    runner.start();\n}\n");
        file_put_contents($root . '/src/engine.rs', "#[cfg(test)]\nuse mockall::automock;\n\npub struct Engine;\n\n#[cfg_attr(test, automock)]\nimpl Engine {\n    pub fn run(&self) {}\n\n    fn start(&self) {}\n}\n\n#[cfg(test)]\nmod tests {\n    use super::MockEngine;\n\n    #[test]\n    fn runs() {\n        let mut engine = MockEngine::new();\n        engine.expect_start().return_const(());\n        engine.expect_run().return_const(());\n        engine.run();\n        assert!(true);\n    }\n}\n");
        $pdo = $this->freshTestDatabase();
        try {
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
            $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
            $services = ArchitectureQueryService::forDatabase($pdo)->services();
            $byName = [];
            foreach ($services->healthQueries->architectureHealth($projectId, limit: 100)->data['dead_code_candidates'] as $candidate) {
                $byName[$candidate['component']['canonical_name']] = $candidate;
            }

            $private = 'crate::engine::Engine::start';
            assertArrayHasKey($private, $byName);
            assertSame('probable', $byName[$private]['confidence'], $byName[$private]['reason']);
            // The mock is live: it doubles Engine and stubs start; and nothing calls it a breach.
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM edges e JOIN nodes t ON t.id = e.target_id WHERE e.kind = 'doubles' AND t.canonical_name = 'crate::engine::Engine'")->fetchColumn());
            $categories = array_column($services->testFidelityQueries->auditTests($projectId, limit: 200)->data['items'], 'category');
            assertSame([], array_values(array_filter($categories, static fn(string $category): bool => $category === 'VISIBILITY_BREACH')));
        } finally {
            unset($pdo);
            $this->removeTempTree($root);
        }
    }
}
