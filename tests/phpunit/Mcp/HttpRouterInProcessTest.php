<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp {

    use Knossos\Tests\Phpunit\KnossosTestCase;
    use RuntimeException;

    /**
     * Drives `bin/http-router.php` in-process, the way `php -S` runs it per request:
     * `$_SERVER` set, environment set, the script included and its echoed body
     * captured. The subprocess {@see HttpRouterTest} proves the wiring end to end
     * but loses its pcov data locally; this one asserts the router's own decisions
     * (path gate, roots gate, env parsing, the Throwable backstop) where coverage
     * and mutation testing can see them.
     *
     * In the CLI SAPI header() and http_response_code() are inert, so every
     * assertion is on the echoed body. `getallheaders()` does not exist in the CLI
     * either; the global shim declared at the bottom of this file returns
     * {@see self::$requestHeaders}, which is [] unless a test sets it, so the
     * "no headers" behaviour of the CLI is the default.
     */
    final class HttpRouterInProcessTest extends KnossosTestCase
    {
        /** @var array<string, string> request headers served by the getallheaders() shim */
        public static array $requestHeaders = [];

        private const ENV = [
            'KNOSSOS_ALLOWED_ROOTS',
            'KNOSSOS_ROOTS_FILE',
            'KNOSSOS_DATA_DIR',
            'KNOSSOS_HTTP_ALLOWED_HOSTS',
            'KNOSSOS_HTTP_ALLOWED_ORIGINS',
            'KNOSSOS_HTTP_BEARER_TOKEN',
        ];

        /** @var array<string, string|false> */
        private array $previousEnv = [];
        /** @var array<string, mixed> */
        private array $previousServer = [];
        private string|false $previousMemoryLimit = false;
        private string $dataDir = '';

        protected function setUp(): void
        {
            parent::setUp();
            foreach (self::ENV as $name) {
                $this->previousEnv[$name] = getenv($name);
                putenv($name);
            }
            $this->previousServer = $_SERVER;
            $this->previousMemoryLimit = ini_get('memory_limit');
            self::$requestHeaders = [];

            $base = tempnam(sys_get_temp_dir(), 'knossos-stale-router-');
            if ($base === false) {
                throw new RuntimeException('Unable to allocate a temporary directory.');
            }
            unlink($base);
            if (!mkdir($base, 0700) && !is_dir($base)) {
                throw new RuntimeException('Unable to create a temporary directory.');
            }
            $this->dataDir = $base;
            putenv('KNOSSOS_DATA_DIR=' . $this->dataDir);
            unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $_SERVER['REMOTE_ADDR']);
        }

        protected function tearDown(): void
        {
            foreach ($this->previousEnv as $name => $value) {
                putenv(is_string($value) ? $name . '=' . $value : $name);
            }
            $_SERVER = $this->previousServer;
            if (is_string($this->previousMemoryLimit)) {
                ini_set('memory_limit', $this->previousMemoryLimit);
            }
            self::$requestHeaders = [];
            if ($this->dataDir !== '' && is_dir($this->dataDir)) {
                $this->removeTempTree($this->dataDir);
            }
            parent::tearDown();
        }

        public function testRootPathIsNotFoundAndOpensNoDatabase(): void
        {
            $_SERVER['REQUEST_URI'] = '/';

            assertSame('{"error":"Not found"}', self::route());
            assertSame([], self::entriesOf($this->dataDir));
        }

        public function testOnlyTheExactMcpPathPassesTheGate(): void
        {
            foreach (['/mcp/extra', '/mcpx', '/MCP', '/api/mcp'] as $uri) {
                $_SERVER['REQUEST_URI'] = $uri;
                assertSame('{"error":"Not found"}', self::route(), $uri);
            }
            assertSame([], self::entriesOf($this->dataDir));
        }

        public function testMissingRequestUriIsTreatedAsEmptyAndNotFound(): void
        {
            assertSame('{"error":"Not found"}', self::route());
        }

        public function testQueryStringIsIgnoredByThePathGate(): void
        {
            $_SERVER['REQUEST_URI'] = '/mcp?x=1';

            // Past the 404: with no roots configured the next gate answers.
            $decoded = self::decode(self::route());
            assertSame(true, str_starts_with($decoded['error'], 'No allowed roots.'));
        }

        public function testNoRootsNamesTheRootsFileBesideTheDatabaseAndOpensNothing(): void
        {
            $_SERVER['REQUEST_URI'] = '/mcp';
            $_SERVER['REQUEST_METHOD'] = 'POST';

            $body = self::route();
            $rootsFile = $this->dataDir . '/roots.json';

            $decoded = self::decode($body);
            assertSame(['error'], array_keys($decoded));
            assertSame(
                sprintf('No allowed roots. Set KNOSSOS_ALLOWED_ROOTS, or create %s containing {"roots": ["/absolute/path"]}.', $rootsFile),
                $decoded['error'],
            );
            // Slashes are written unescaped so the path can be copied from a log.
            assertContains($rootsFile, $body);
            assertSame(false, str_contains($body, '\/'));
            // The endpoint is never built, so no database is opened.
            assertSame(false, is_file($this->dataDir . '/knossos.sqlite'));
            assertSame([], self::entriesOf($this->dataDir));
        }

        public function testRootsVariableOfOnlySeparatorsCountsAsNoRoots(): void
        {
            putenv('KNOSSOS_ALLOWED_ROOTS=' . PATH_SEPARATOR . PATH_SEPARATOR);
            $_SERVER['REQUEST_URI'] = '/mcp';

            $decoded = self::decode(self::route());
            assertSame(true, str_starts_with($decoded['error'], 'No allowed roots.'));
            assertContains($this->dataDir . '/roots.json', $decoded['error']);
        }

        public function testInvalidUtf8InTheRootsFilePathIsSubstitutedNotAnEmptyBody(): void
        {
            $rootsFile = $this->dataDir . "/roots-\xFF\xFE.json";
            putenv('KNOSSOS_ROOTS_FILE=' . $rootsFile);
            $_SERVER['REQUEST_URI'] = '/mcp';

            $body = self::route();

            assertNotSame('', $body);
            $decoded = self::decode($body);
            assertContains($this->dataDir . "/roots-\u{FFFD}\u{FFFD}.json", $decoded['error']);
        }

        public function testRootsFileBesideTheDatabaseIsHonoured(): void
        {
            file_put_contents(
                $this->dataDir . '/roots.json',
                json_encode(['roots' => [self::repositoryRoot() . '/tests/Fixtures/mixed']], JSON_THROW_ON_ERROR),
            );
            $_SERVER['REQUEST_URI'] = '/mcp';
            $_SERVER['REQUEST_METHOD'] = 'GET';

            $decoded = self::decode(self::route());

            // Past the roots gate: the endpoint itself answers (no Host header).
            assertSame(['error' => 'Unrecognized Host header.'], $decoded);
        }

        public function testMissingHostIsRefusedByTheEndpointNotTheRouter(): void
        {
            $this->allowFixtureRoot();
            $_SERVER['REQUEST_URI'] = '/mcp';
            $_SERVER['REQUEST_METHOD'] = 'GET';

            assertSame(['error' => 'Unrecognized Host header.'], self::decode(self::route()));
        }

        public function testEmptyHostsVariableFallsBackToTheDefaultHosts(): void
        {
            $this->allowFixtureRoot();
            putenv('KNOSSOS_HTTP_ALLOWED_HOSTS=');
            $_SERVER['REQUEST_URI'] = '/mcp';
            $_SERVER['REQUEST_METHOD'] = 'GET';

            // No Host at all: refused exactly as with the variable unset.
            assertSame(['error' => 'Unrecognized Host header.'], self::decode(self::route()));

            // Each default host passes the Host check and reaches the method check.
            foreach (['127.0.0.1:8080', 'localhost:8080', '[::1]:8080'] as $host) {
                self::$requestHeaders = ['Host' => $host];
                assertSame(
                    ['error' => 'Only POST and session DELETE are supported.'],
                    self::decode(self::route()),
                    $host,
                );
            }

            // A host outside the defaults is still refused.
            self::$requestHeaders = ['Host' => 'example.test:8080'];
            assertSame(['error' => 'Unrecognized Host header.'], self::decode(self::route()));
        }

        public function testConfiguredHostsAreTrimmedAndLowercased(): void
        {
            $this->allowFixtureRoot();
            putenv('KNOSSOS_HTTP_ALLOWED_HOSTS= Example.TEST:9000 , ,other.test:1');
            $_SERVER['REQUEST_URI'] = '/mcp';
            $_SERVER['REQUEST_METHOD'] = 'GET';

            foreach (['example.test:9000', 'other.test:1'] as $host) {
                self::$requestHeaders = ['Host' => $host];
                assertSame(['error' => 'Only POST and session DELETE are supported.'], self::decode(self::route()), $host);
            }

            // The configured list replaces the defaults rather than extending them.
            self::$requestHeaders = ['Host' => '127.0.0.1:8080'];
            assertSame(['error' => 'Unrecognized Host header.'], self::decode(self::route()));
        }

        public function testConfiguredOriginsAreTrimmedAndReplaceTheDefaults(): void
        {
            $this->allowFixtureRoot();
            putenv('KNOSSOS_HTTP_ALLOWED_ORIGINS= https://app.test , ');
            $_SERVER['REQUEST_URI'] = '/mcp';
            $_SERVER['REQUEST_METHOD'] = 'GET';

            self::$requestHeaders = ['Host' => '127.0.0.1:8080', 'Origin' => 'https://app.test'];
            assertSame(['error' => 'Only POST and session DELETE are supported.'], self::decode(self::route()));

            self::$requestHeaders = ['Host' => '127.0.0.1:8080', 'Origin' => 'http://127.0.0.1:8080'];
            assertSame(['error' => 'Origin is not allowed.'], self::decode(self::route()));
        }

        public function testDefaultOriginsApplyWhenTheVariableIsEmpty(): void
        {
            $this->allowFixtureRoot();
            putenv('KNOSSOS_HTTP_ALLOWED_ORIGINS=');
            $_SERVER['REQUEST_URI'] = '/mcp';
            $_SERVER['REQUEST_METHOD'] = 'GET';

            foreach (['http://127.0.0.1:8080', 'http://localhost:8080', 'http://[::1]:8080'] as $origin) {
                self::$requestHeaders = ['Host' => '127.0.0.1:8080', 'Origin' => $origin];
                assertSame(['error' => 'Only POST and session DELETE are supported.'], self::decode(self::route()), $origin);
            }

            self::$requestHeaders = ['Host' => '127.0.0.1:8080', 'Origin' => 'https://evil.test'];
            assertSame(['error' => 'Origin is not allowed.'], self::decode(self::route()));
        }

        public function testEmptyTokenMeansNoTokenWhileASetTokenDemandsAuthorization(): void
        {
            $this->allowFixtureRoot();
            $_SERVER['REQUEST_URI'] = '/mcp';
            $_SERVER['REQUEST_METHOD'] = 'POST';
            self::$requestHeaders = ['Host' => '127.0.0.1:8080'];

            // Empty token: no Authorization is demanded, the request reaches the
            // envelope checks (no Content-Type) instead of a 401.
            putenv('KNOSSOS_HTTP_BEARER_TOKEN=');
            assertSame(['error' => 'Content-Type must be application/json.'], self::decode(self::route()));

            // A set token: the same request is refused for missing authentication.
            putenv('KNOSSOS_HTTP_BEARER_TOKEN=router-secret');
            assertSame(['error' => 'Bearer authentication is required.'], self::decode(self::route()));

            // ... and the right token lets it through to the envelope checks.
            self::$requestHeaders['Authorization'] = 'Bearer router-secret';
            assertSame(['error' => 'Content-Type must be application/json.'], self::decode(self::route()));
        }

        public function testEmptyTokenKeepsTheEndpointLoopbackOnlyUsingRemoteAddr(): void
        {
            $this->allowFixtureRoot();
            putenv('KNOSSOS_HTTP_BEARER_TOKEN=');
            $_SERVER['REQUEST_URI'] = '/mcp';
            $_SERVER['REQUEST_METHOD'] = 'POST';
            self::$requestHeaders = ['Host' => '127.0.0.1:8080'];

            $_SERVER['REMOTE_ADDR'] = '10.1.2.3';
            assertSame(
                ['error' => 'Bearer authentication is required from non-loopback clients.'],
                self::decode(self::route()),
            );

            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            assertSame(['error' => 'Content-Type must be application/json.'], self::decode(self::route()));

            // A non-string REMOTE_ADDR is no peer at all, so the loopback check is skipped.
            $_SERVER['REMOTE_ADDR'] = ['10.1.2.3'];
            assertSame(['error' => 'Content-Type must be application/json.'], self::decode(self::route()));
        }

        public function testMissingRequestMethodDefaultsToGet(): void
        {
            $this->allowFixtureRoot();
            $_SERVER['REQUEST_URI'] = '/mcp';
            self::$requestHeaders = ['Host' => '127.0.0.1:8080'];

            assertSame(['error' => 'Only POST and session DELETE are supported.'], self::decode(self::route()));
        }

        public function testAThrowableFromHandleIsLoggedAndAnsweredWithAGenericInternalError(): void
        {
            $this->allowFixtureRoot();
            $_SERVER['REQUEST_URI'] = '/mcp';
            // The router is strict_types, so a non-string method makes the call
            // to handle() throw a TypeError inside the backstop's try block.
            $_SERVER['REQUEST_METHOD'] = 123;

            $body = '';
            $logged = $this->errorLogOf(function () use (&$body): void {
                $body = self::route();
            });

            assertSame(
                '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}',
                $body,
            );
            // The raw detail goes to the operator's log, never to the client.
            assertContains('knossos http-router: ', $logged);
            assertContains('handle()', $logged);
            assertSame(false, str_contains($body, 'handle'));
        }

        private function allowFixtureRoot(): void
        {
            putenv('KNOSSOS_ALLOWED_ROOTS=' . PATH_SEPARATOR . self::repositoryRoot() . '/tests/Fixtures/mixed' . PATH_SEPARATOR);
        }

        /** Include the router as `php -S` would and return what it echoed. */
        private static function route(): string
        {
            ob_start();
            try {
                include self::repositoryRoot() . '/bin/http-router.php';
            } finally {
                $out = (string) ob_get_clean();
            }

            return $out;
        }

        /** @return array<string, mixed> */
        private static function decode(string $body): array
        {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException('Router body is not a JSON object: ' . $body);
            }

            return $decoded;
        }

        /** @return list<string> */
        private static function entriesOf(string $directory): array
        {
            return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        }
    }
}

namespace {

    // The CLI SAPI has no getallheaders(); the router falls back to [] when it is
    // absent. This shim stands in for the web SAPI's version so a test can supply
    // request headers, and returns [] (the CLI behaviour) unless one does.
    if (!function_exists('getallheaders')) {
        /** @return array<string, string> */
        function getallheaders(): array
        {
            return \Knossos\Tests\Phpunit\Mcp\HttpRouterInProcessTest::$requestHeaders;
        }
    }
}
