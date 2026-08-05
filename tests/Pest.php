<?php

declare(strict_types=1);

use Capell\Admin\Tests\AdminTestCase;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
use Capell\Core\Tests\CoreTestCase;
use Capell\Frontend\Tests\FrontendTestCase;
use Capell\Installer\Tests\InstallerTestCase;
use Capell\Marketplace\Actions\EvaluateMarketplaceEnvironmentReadinessAction;
use Capell\Marketplace\Data\MarketplaceEnvironmentReadinessData;
use Capell\Marketplace\Data\MarketplaceReadinessCheckData;
use Capell\Marketplace\Enums\MarketplaceInstallCapability;
use Capell\Marketplace\Enums\MarketplaceReadinessStatus;
use Capell\Marketplace\Tests\MarketplaceTestCase;
use Capell\Tests\PackagesTestCase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\artisan;

use PHPUnit\Framework\Assert;
use Spatie\LaravelData\DataCollection;

pest()->extends(PackagesTestCase::class)->group('core')->in(__DIR__);
pest()->extend(CoreTestCase::class)->group('core')->in('../packages/core/tests', '../Packages/core/tests');
pest()->extend(AdminTestCase::class)->group('admin')->in('../packages/admin/tests', '../Packages/admin/tests');
pest()->extend(FrontendTestCase::class)->group('frontend')->in('../packages/frontend/tests', '../Packages/frontend/tests');
pest()->extend(InstallerTestCase::class)->group('installer')->in('../packages/installer/tests', '../Packages/installer/tests');
pest()->extend(MarketplaceTestCase::class)->group('marketplace')->in('../packages/marketplace/tests', '../Packages/marketplace/tests');

/**
 * The Composer invocation this host actually uses.
 *
 * Composer commands are argv arrays built from RuntimeBinaryResolver, so a test
 * that pins the literal string "composer" pins the resolver's answer on one
 * machine. Asking the resolver keeps the assertion about the command shape.
 *
 * @return list<string>
 */
function capellComposerArgv(): array
{
    return new RuntimeBinaryResolver()->composer();
}

/**
 * The Composer run timeout this host actually uses.
 *
 * Composer runs take their budget from `capell.process.composer.timeout_seconds`,
 * which an operator may tune. Pinning the literal default would pass here and
 * fail on any host that had set CAPELL_COMPOSER_TIMEOUT_SECONDS, so ask for the
 * configured value and keep the assertion about the budget being applied.
 */
function capellComposerTimeoutSeconds(): int
{
    $configured = config('capell.process.composer.timeout_seconds', 600);

    return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 600;
}

/**
 * Declare the host a Marketplace test assumes.
 *
 * Environment readiness probes the machine the suite happens to run on — a test
 * runner's release root can sit behind a symlink, and server-side tooling is off
 * by default — so a test about per-attempt behaviour has to state the host it
 * assumes rather than inherit the runner's.
 */
function fakeMarketplaceEnvironmentReadiness(
    MarketplaceInstallCapability $capability = MarketplaceInstallCapability::Automated,
    MarketplaceReadinessStatus $processExecutionStatus = MarketplaceReadinessStatus::Pass,
): MarketplaceEnvironmentReadinessData {
    $readiness = new MarketplaceEnvironmentReadinessData(
        capability: $capability,
        checks: [
            new MarketplaceReadinessCheckData(
                key: 'process_execution',
                status: $processExecutionStatus,
                message: 'Process execution readiness for this test host.',
                remediation: $processExecutionStatus === MarketplaceReadinessStatus::Pass
                    ? null
                    : 'Remove proc_open from disable_functions in php.ini.',
                docsAnchor: $processExecutionStatus === MarketplaceReadinessStatus::Pass
                    ? null
                    : 'process-execution',
                byDesign: $processExecutionStatus === MarketplaceReadinessStatus::Fail,
            ),
        ],
    );

    bindFakeAction(EvaluateMarketplaceEnvironmentReadinessAction::class, $readiness);

    return $readiness;
}

/**
 * Bind a fake for a final action class into the Laravel container.
 * Returns a stdClass spy with `called` (bool) and `args` (array) properties.
 *
 * @param  class-string  $actionClass
 */
function bindFakeAction(string $actionClass, mixed $returnValue = null): stdClass
{
    $spy = new stdClass;
    $spy->called = false;
    $spy->args = [];

    app()->bind($actionClass, fn (): object => new readonly class($returnValue, $spy)
    {
        public function __construct(
            private mixed $returnValue,
            private stdClass $spy,
        ) {}

        public function handle(mixed ...$arguments): mixed
        {
            $this->spy->called = true;
            $this->spy->args = $arguments;

            return $this->returnValue;
        }
    });

    return $spy;
}

/**
 * Execute a preconfigured Action through Laravel Actions' object entry point.
 *
 * @param  class-string  $actionClass
 */
function runBoundAction(string $actionClass, object $action, mixed ...$arguments): mixed
{
    app()->instance($actionClass, $action);

    return $actionClass::run(...$arguments);
}

function fakeMarketplace(array $stubs = []): void
{
    config([
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
        'capell-marketplace.instance.id' => '00000000-0000-4000-8000-000000000001',
        'capell-marketplace.marketplace.webhook_secret' => 'test-marketplace-secret',
    ]);

    Http::fake($stubs);
}

function decodedSettingPayload(string $group, string $name): mixed
{
    $payload = DB::table('settings')
        ->where('group', $group)
        ->where('name', $name)
        ->value('payload');

    throw_unless(is_string($payload), RuntimeException::class, sprintf('Expected [%s.%s] setting payload.', $group, $name));

    return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $parameters
 */
function artisanCommand(string $command, array $parameters = []): PendingCommand
{
    $pendingCommand = artisan($command, $parameters);

    throw_unless($pendingCommand instanceof PendingCommand, RuntimeException::class, 'Expected Laravel test artisan command output to be mocked.');

    return $pendingCommand;
}

function filamentObjectName(object $object): ?string
{
    $name = filamentObjectMethod($object, 'getName');

    return is_string($name) ? $name : null;
}

function filamentObjectKey(object $object): ?string
{
    $key = filamentObjectMethod($object, 'getKey');

    return is_string($key) ? $key : null;
}

function filamentObjectDefaultState(object $object): mixed
{
    return filamentObjectMethod($object, 'getDefaultState');
}

function filamentObjectIcon(object $object): mixed
{
    return filamentObjectMethod($object, 'getIcon');
}

function filamentObjectTooltip(object $object): mixed
{
    return filamentObjectMethod($object, 'getTooltip');
}

function filamentText(mixed $value): string
{
    if ($value instanceof Htmlable) {
        return $value->toHtml();
    }

    return is_string($value) ? $value : '';
}

function filamentObjectMethod(object $object, string $method): mixed
{
    $callable = [$object, $method];

    if (! is_callable($callable)) {
        throw new RuntimeException(sprintf('Expected object of class [%s] to have callable method [%s].', $object::class, $method));
    }

    return $callable();
}

/**
 * @template TValue
 *
 * @param  TValue|null  $value
 * @return TValue
 */
function expectPresent(mixed $value): mixed
{
    Assert::assertNotNull($value);

    return $value;
}

/**
 * @template TValue
 *
 * @param  Collection<int, TValue>|DataCollection<int, TValue>  $items
 * @return TValue|null
 */
function firstDataItem(Collection|DataCollection $items): mixed
{
    if ($items instanceof DataCollection) {
        return $items->toCollection()->first();
    }

    return $items->first();
}

function normalizeHtml(string $html): string
{
    // Remove all HTML comments (including Livewire diff comments)
    $html = preg_replace('/<!--.*?-->/s', '', $html);

    // Collapse all whitespace to a single space
    $html = preg_replace('/\s+/', ' ', (string) $html);

    return trim((string) $html);
}

TestResponse::macro('toContainHtmlIgnoringCommentsAndWhitespace', function (string $expectedHtml): object {
    $actual = normalizeHtml($this->getContent());
    $expected = normalizeHtml($expectedHtml);
    if (! str_contains($actual, $expected)) {
        $message = "Failed asserting that normalized HTML contains expected content.\n\n" .
            "--- Normalized Actual ---\n" . $actual . "\n\n" .
            "--- Normalized Expected ---\n" . $expected . "\n";
        Assert::fail($message);
    }

    return $this;
});

/**
 * @param  list<string>  $surfaces
 * @param  array<string, list<string>>  $providers
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function capellManifestV3Array(
    string $name = 'vendor/package',
    array $surfaces = ['admin'],
    ?string $namespace = null,
    array $providers = [],
    array $overrides = [],
): array {
    $slug = str($name)->afterLast('/')->replace('_', '-')->slug()->toString();

    $manifest = [
        'manifest-version' => 3,
        'name' => $name,
        'slug' => $slug,
        'displayName' => str($slug)->replace('-', ' ')->title()->toString(),
        'kind' => 'package',
        'capellApiVersion' => '^1.0',
        'version' => '1.x-dev',
        'description' => 'Test package manifest.',
        'product' => ['group' => 'Tests', 'tier' => 'free', 'bundle' => null],
        'surfaces' => $surfaces,
        'dependencies' => ['requires' => [], 'supports' => [], 'conflicts' => []],
        'providers' => array_replace([
            'metadata' => [],
            'install' => [],
            'runtime' => [],
            'auth' => [],
            'admin' => [],
            'frontend' => [],
        ], $providers),
        'contributes' => [],
        'database' => ['migrations' => false, 'settings' => false, 'requiredTables' => []],
        'commands' => ['install' => null, 'setup' => null, 'demo' => null, 'doctor' => null],
        'settings' => [],
        'permissions' => [],
        'capabilities' => [],
        'performance' => [
            'frontendRenderBudgetMs' => 0,
            'adminQueryBudget' => 0,
            'cacheTags' => [],
            'cacheSafety' => [
                'cacheable' => false,
                'variesBy' => [],
                'sensitiveOutput' => false,
                'invalidationSources' => [],
                'queueInvalidation' => false,
            ],
        ],
        'healthChecks' => [],
        'commercial' => [
            'proposedLicense' => 'free',
            'requestedCertification' => 'community',
            'supportPolicy' => 'community',
            'privateDocsRequested' => false,
        ],
        'marketplace' => [
            'summary' => 'Test package manifest.',
            'screenshots' => [],
            'categories' => ['tests'],
        ],
    ];

    if ($namespace !== null) {
        $manifest['namespace'] = $namespace;
    }

    return array_replace_recursive($manifest, $overrides);
}
