<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class PythonFrameworksTest extends KnossosTestCase
{
    /**
     * `task.delay()` and `task.apply_async()` schedule the task rather than run
     * it: a `dispatches` edge, which flow follows, where a call to the task
     * itself stays `calls`. A signature (`task.s()`, `.si()`, `.signature()`)
     * schedules it once a chain, a chord or its caller applies it: a probable
     * dispatch, made certain by a submission of the same task beside it.
     */
    #[Group('python-frameworks')]
    public function testCelerySubmissionsDispatchTheTaskTheyQueue(): void
    {
        $client = $this->pythonWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => self::repositoryRoot() . '/tests/Fixtures/python-celery',
            'files' => ['app/__init__.py', 'app/billing.py', 'app/submissions.py'],
        ]));
        $client->shutdown();
        $edges = array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions));
        $tuples = array_map(
            fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
            array_values(array_filter($edges, fn(EdgeFact $edge): bool => in_array($edge->kind, ['calls', 'dispatches'], true))),
        );

        assertArrayContains(['calls', 'py:function:app.submissions.place', 'py:function:app.submissions.deliver'], $tuples);
        assertArrayContains(['dispatches', 'py:function:app.submissions.place', 'py:function:app.submissions.deliver'], $tuples);
        assertArrayContains(['dispatches', 'py:function:app.submissions.nightly', 'py:function:app.billing.reconcile_orders'], $tuples);
        $dispatch = function (string $source, string $target) use ($edges): EdgeFact {
            $found = array_values(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'dispatches' && $edge->sourceReference === $source && $edge->targetReference === $target));
            assertSame(1, count($found), "{$source} -> {$target}");
            return $found[0];
        };
        assertSame('certain', $dispatch('py:function:app.submissions.place', 'py:function:app.submissions.deliver')->confidence->value);
        // A signature, returned or handed to a chain, is a probable dispatch.
        $prepared = $dispatch('py:function:app.submissions.prepare', 'py:function:app.submissions.deliver');
        assertSame('probable', $prepared->confidence->value);
        assertSame(['celery' => 'signature'], $prepared->attributes);
        assertSame('probable', $dispatch('py:function:app.submissions.batch', 'py:function:app.submissions.deliver')->confidence->value);
        assertSame('probable', $dispatch('py:function:app.submissions.batch', 'py:function:app.billing.reconcile_orders')->confidence->value);
        // A submission beside the signature makes the dispatch certain, whichever came first.
        $retried = $dispatch('py:function:app.submissions.retry', 'py:function:app.submissions.deliver');
        assertSame('certain', $retried->confidence->value);
        assertSame([], $retried->attributes);
        // The submission method is no function of the project.
        foreach ($tuples as [, , $target]) {
            assertSame(false, (bool) preg_match('/\.(delay|apply_async|s|si|signature)$/', $target), $target);
        }
    }

    /**
     * A REST framework view that reads `validated_data["method"]` gets what the
     * serializer's `validate_method` returned, a function it then calls, so it
     * depends on what that method returns. The serializer comes from the view's
     * `serializer_class` through `self.get_serializer()`, or from building it.
     */
    #[Group('python-frameworks')]
    public function testAViewReadingAValidatedFieldDependsOnTheValidatorThatChoseIt(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('python-drf');
        try {
            $dependants = ArchitectureQueryService::forDatabase($pdo)->impactAnalysis($projectId, 'app.actions.archive')->data['dependants'];
            $names = array_map(static fn(array $row): string => $row['node']['canonical_name'], $dependants);

            assertArrayContains('app.serializers.BulkSerializer::validate_method', $names);
            assertArrayContains('app.views.BulkView::post', $names);
            assertArrayContains('app.views.DirectView::post', $names);
            // A field with no validator of its own, and a view with no serializer class, name nothing.
            assertSame(false, in_array('app.views.DirectView::put', $names, true));
            assertSame(false, in_array('app.views.Untyped::post', $names, true));
            $edge = $pdo->query("SELECT e.confidence, e.attributes_json FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id WHERE s.canonical_name = 'app.views.BulkView::post' AND t.canonical_name = 'app.serializers.BulkSerializer::validate_method'")->fetch();
            assertSame('probable', $edge['confidence']);
            assertSame('validated_field', json_decode($edge['attributes_json'], true)['drf']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('python-frameworks')]
    public function testPythonFrameworkEnrichmentExtractsFastapiDjangoDependenciesSettingsAndTasks(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/python-frameworks';
        $files = [
            'app/__init__.py',
            'app/dependencies.py',
            'app/django_app.py',
            'app/fastapi_app.py',
            'app/settings.py',
        ];
        $client = $this->pythonWorkerClient();
        $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => $files]));
        $client->shutdown();
        $nodes = array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions));
        $edges = array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions));
        $diagnostics = array_merge(...array_map(fn(ScanContribution $item): array => $item->diagnostics, $contributions));
        $byCanonical = [];
        foreach ($nodes as $node) {
            $byCanonical[$node->canonicalName] = $node;
        }

        assertSame('fastapi', $byCanonical['GET /api/orders => app.fastapi_app.list_orders']->attributes['framework']);
        assertSame('django', $byCanonical['ANY /checkout/ => checkout_view']->attributes['framework']);
        assertSame(['django.model'], $byCanonical['app.django_app.Product']->attributes['python_framework_roles']);
        assertSame(['django.middleware'], $byCanonical['app.django_app.AuditMiddleware']->attributes['python_framework_roles']);
        assertSame(['python.task'], $byCanonical['app.django_app.reconcile_orders']->attributes['python_framework_roles']);
        assertSame(['app'], $byCanonical['app.settings.INSTALLED_APPS']->attributes['value']);

        $edgeTuples = array_map(fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference], $edges);
        assertArrayContains(['routes_to', 'py:route:GET /api/orders => app.fastapi_app.list_orders', 'py:function:app.fastapi_app.list_orders'], $edgeTuples);
        assertArrayContains(['depends_on', 'py:function:app.fastapi_app.list_orders', 'py:function:app.dependencies.require_admin'], $edgeTuples);
        assertArrayContains(['depends_on', 'py:function:app.fastapi_app.list_orders', 'py:function:app.dependencies.load_user'], $edgeTuples);
        assertArrayContains(['mounts', 'py:module:app.fastapi_app', 'py:router:app.fastapi_app.router'], $edgeTuples);
        assertArrayContains(['uses_middleware', 'py:module:app.fastapi_app', 'py:class:app.fastapi_app.AuthenticationMiddleware'], $edgeTuples);
        assertArrayContains(['routes_to', 'py:route:ANY /products/ => ProductView', 'py:class:app.django_app.ProductView'], $edgeTuples);
        assertArrayContains(['configures', 'py:module:app.settings', 'py:setting:app.settings.INSTALLED_APPS'], $edgeTuples);
        assertSame(['PY_DYNAMIC_ROUTE_PATH', 'PY_DYNAMIC_ROUTE_PATH'], array_column($diagnostics, 'code'));

        $database = tempnam(sys_get_temp_dir(), 'knossos-python-frameworks-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate Python framework database.');
        }
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, 'Python Frameworks');
            assertSame(5, $result->data['parsed_files']);
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'fastapi.route_handler'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'django.model'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'django.middleware'")->fetchColumn());
            assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'python.task'")->fetchColumn());
        } finally {
            unset($pdo);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                @unlink($candidate);
            }
        }
    }
}
