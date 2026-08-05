<?php

declare(strict_types=1);

use Capell\Core\Contracts\PackageLifecycleAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Composer\ComposerStateSnapshot;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Tests\Support\Fixtures\Autoload\LifecycleRecorderAction;
use Capell\Marketplace\Actions\CancelMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\FindStuckMarketplaceInstallOperationsAction;
use Capell\Marketplace\Actions\NotifyMarketplaceInstallCompletedAction;
use Capell\Marketplace\Actions\PropagateMarketplaceRuntimeStateAction;
use Capell\Marketplace\Actions\RestoreComposerStateAction;
use Capell\Marketplace\Actions\RunPostOperationHealthCheckAction;
use Capell\Marketplace\Contracts\MarketplaceAuthenticatedComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceComposerScriptRunner;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Data\MarketplaceHealthCheckResultData;
use Capell\Marketplace\Enums\MarketplaceHealthProbeOutcome;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceInstallNotifications;
use Capell\Marketplace\Support\MarketplaceWorkerHeartbeat;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Notifications\BroadcastNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

require_once dirname(__DIR__, 5) . '/tests/Support/InstallFilesystemLock.php';

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    preserveTestbenchPackageManifestFilesDuringPackageRemoval();
});

it('guards each install attempt and bounds lock-contention retries', function (): void {
    $job = new RunMarketplaceInstallAttemptJob(42);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->tries)->toBe(0)
        ->and($job->backoff())->toBe([30, 60, 120, 300]);
});

it('refuses to run against a node-local cache store where the composer lock cannot hold', function (): void {
    config()->set('capell.multi_node', true);
    config()->set('cache.default', 'array');
    config()->set('cache.stores.array.driver', 'array');

    $attempt = marketplaceOperationAttempt();

    app()->instance(MarketplaceComposerRunner::class, new class implements MarketplaceComposerRunner
    {
        public static bool $ran = false;

        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            self::$ran = true;

            return new MarketplaceComposerResultData(exitCode: 0, output: '', errorOutput: '');
        }
    });

    $composer = resolve(MarketplaceComposerRunner::class);

    expect(fn (): mixed => new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle($composer))
        ->toThrow(RuntimeException::class, 'CAPELL_MULTI_NODE=true');

    expect($composer::$ran)->toBeFalse()
        ->and($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Queued);
});

it('records a worker heartbeat as soon as the install job is picked up', function (): void {
    MarketplaceWorkerHeartbeat::forget();

    $attempt = marketplaceOperationAttempt();

    app()->instance(MarketplaceComposerRunner::class, new class implements MarketplaceComposerRunner
    {
        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            return new MarketplaceComposerResultData(exitCode: 1, output: '', errorOutput: 'Composer failed hard');
        }
    });

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));

    expect(MarketplaceWorkerHeartbeat::isFresh())->toBeTrue();
});

it('marks composer failures without sending the old failure notification', function (): void {
    Notification::fake();
    $superAdmin = test()->createUserWithRole('super_admin');

    $attempt = marketplaceOperationAttempt();

    app()->instance(MarketplaceComposerRunner::class, new class implements MarketplaceComposerRunner
    {
        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            return new MarketplaceComposerResultData(
                exitCode: 1,
                output: 'Installing dependencies',
                errorOutput: 'Composer failed hard',
            );
        }
    });

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));

    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_reason)->toBe('Composer failed hard')
        ->and($attempt->started_at)->not->toBeNull()
        ->and($attempt->completed_at)->not->toBeNull()
        ->and($attempt->resolved_at)->toBeNull()
        ->and($attempt->attempt_count)->toBe(1)
        ->and($attempt->heartbeat_at)->not->toBeNull()
        ->and($attempt->current_stage)->toBe(MarketplaceInstallFailureStage::Composer->value)
        ->and($attempt->runtime_ms)->toBeInt()
        ->and($attempt->peak_memory_bytes)->toBeGreaterThan(0)
        ->and($attempt->query_count)->toBeGreaterThan(0)
        ->and($attempt->failure_context)->toMatchArray([
            'type' => MarketplaceInstallFailureType::Unknown->value,
            'stage' => MarketplaceInstallFailureStage::Composer->value,
            'reason' => 'Composer failed hard',
        ]);

    Notification::assertNothingSentTo($superAdmin);
});

it('redacts composer secrets before storing operation failure diagnostics', function (): void {
    $attempt = marketplaceOperationAttempt();

    app()->instance(MarketplaceComposerRunner::class, new class implements MarketplaceComposerRunner
    {
        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            return new MarketplaceComposerResultData(
                exitCode: 1,
                output: 'Loading repositories token=output-secret',
                errorOutput: 'HTTP 401 password=hunter2 Bearer abc+/= {"github-oauth":{"github.com":"ghp_secret_token"}}',
            );
        }
    });

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));

    $attempt->refresh();
    $failedEvent = $attempt->events()
        ->where('message', __('capell-marketplace::marketplace.operations.timeline_composer_failed'))
        ->firstOrFail();

    expect($attempt->failure_reason)
        ->not->toContain('hunter2')
        ->not->toContain('abc+/=')
        ->not->toContain('ghp_secret_token')
        ->and($attempt->error_excerpt)
        ->not->toContain('hunter2')
        ->not->toContain('abc+/=')
        ->not->toContain('ghp_secret_token')
        ->and($attempt->output_excerpt)
        ->not->toContain('output-secret')
        ->and($failedEvent->output_excerpt)
        ->not->toContain('hunter2')
        ->not->toContain('abc+/=')
        ->not->toContain('ghp_secret_token');
});

it('passes encrypted marketplace composer auth to authenticated composer runners', function (): void {
    $composerAuth = [
        'github-oauth' => [
            'github.com' => 'ghp_secret_token',
        ],
    ];

    $attempt = marketplaceOperationAttempt([
        'context' => [
            'composer_auth_encrypted' => Crypt::encryptString(json_encode($composerAuth, JSON_THROW_ON_ERROR)),
        ],
    ]);

    $runner = new class implements MarketplaceAuthenticatedComposerRunner
    {
        /** @var array<string, mixed>|null */
        public ?array $composerAuth = null;

        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            throw new RuntimeException('Unauthenticated Composer should not run.');
        }

        public function requireWithComposerAuth(string $composerName, string $versionConstraint, int $timeoutSeconds, array $composerAuth): MarketplaceComposerResultData
        {
            $this->composerAuth = $composerAuth;

            return new MarketplaceComposerResultData(
                exitCode: 1,
                output: '',
                errorOutput: 'Composer failed after authenticated request.',
            );
        }
    };

    app()->instance(MarketplaceComposerRunner::class, $runner);

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));

    expect($runner->composerAuth)->toBe($composerAuth)
        ->and($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Failed);
});

it('marks composer timeouts distinctly', function (): void {
    Notification::fake();

    $attempt = marketplaceOperationAttempt();

    app()->instance(MarketplaceComposerRunner::class, new class implements MarketplaceComposerRunner
    {
        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            return new MarketplaceComposerResultData(
                exitCode: 124,
                output: '',
                errorOutput: 'Timed out',
                timedOut: true,
            );
        }
    });

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));

    expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::TimedOut)
        ->and($attempt->resolved_at)->toBeNull();
});

it('marks composer runner exceptions as failures without sending the old failure notification', function (): void {
    Notification::fake();
    $superAdmin = test()->createUserWithRole('super_admin');

    $attempt = marketplaceOperationAttempt();

    app()->instance(MarketplaceComposerRunner::class, new class implements MarketplaceComposerRunner
    {
        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            throw new RuntimeException('Composer binary is missing.');
        }
    });

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));

    expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_reason)->toBe('Composer binary is missing.')
        ->and($attempt->resolved_at)->toBeNull();

    Notification::assertNothingSentTo($superAdmin);
});

it('broadcasts a replacement notification when an install completes', function (): void {
    Notification::fake();
    $admin = test()->createUserWithRole('super_admin');

    $attempt = marketplaceOperationAttempt([
        'user_id' => (string) $admin->getKey(),
    ]);

    NotifyMarketplaceInstallCompletedAction::run($attempt);

    Notification::assertSentTo(
        $admin,
        BroadcastNotification::class,
        fn (BroadcastNotification $notification): bool => $notification->data['id'] === MarketplaceInstallNotifications::operationId('capell-app/seo-suite')
            && $notification->data['title'] === (string) __('capell-marketplace::marketplace.install.installed')
            && $notification->data['body'] === (string) __('capell-marketplace::marketplace.install.installed_body', [
                'name' => 'SEO Suite',
            ])
            && ($notification->data['status'] ?? null) === 'success'
            && ($notification->data['duration'] ?? null) === 'persistent'
            && ($notification->data['actions'][0]['label'] ?? null) === (string) __('capell-marketplace::marketplace.install.installed_action'),
    );
});

it('runs marketplace package lifecycle actions without requiring legacy command registration', function (): void {
    Notification::fake();
    LifecycleRecorderAction::reset();
    $admin = test()->createUserWithRole('super_admin');
    $packagePath = sys_get_temp_dir() . '/capell-marketplace-job-action-package-' . uniqid();

    File::ensureDirectoryExists($packagePath);
    File::put($packagePath . '/composer.json', json_encode([
        'name' => 'capell-app/marketplace-job-action-package',
        'autoload' => ['psr-4' => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'capell-app/marketplace-job-action-package',
        surfaces: ['shared'],
        overrides: [
            'kind' => 'plugin',
            'displayName' => 'Marketplace Job Action Package',
            'commands' => [
                'install' => 'capell:missing-marketplace-install',
            ],
            'actions' => [
                'install' => LifecycleRecorderAction::class,
            ],
        ],
    ), $packagePath);
    File::put($packagePath . '/capell.json', json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/marketplace-job-action-package',
        'extension_slug' => 'marketplace-job-action-package',
        'extension_name' => 'Marketplace Job Action Package',
        'user_id' => (string) $admin->getKey(),
    ]);

    app()->instance(MarketplaceComposerRunner::class, new readonly class($manifest) implements MarketplaceComposerRunner
    {
        public function __construct(private CapellManifestData $manifest) {}

        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            CapellCore::registerManifestPackage($this->manifest);

            return new MarketplaceComposerResultData(
                exitCode: 0,
                output: 'Package installed.',
                errorOutput: '',
            );
        }
    });

    try {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    $attempt->refresh();

    expect($attempt->failure_reason)->toBeNull()
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and($attempt->resolved_at)->not->toBeNull()
        ->and(LifecycleRecorderAction::$calls)->toBe([
            [
                'package' => 'capell-app/marketplace-job-action-package',
                'arguments' => [],
            ],
        ]);

    Notification::assertSentTo($admin, BroadcastNotification::class);
});

it('runs lifecycle without Composer when the package is already downloaded but not installed', function (): void {
    Notification::fake();
    LifecycleRecorderAction::reset();
    $admin = test()->createUserWithRole('super_admin');
    $packagePath = sys_get_temp_dir() . '/capell-marketplace-downloaded-action-package-' . uniqid();

    File::ensureDirectoryExists($packagePath);
    File::put($packagePath . '/composer.json', json_encode([
        'name' => 'capell-app/marketplace-downloaded-action-package',
        'autoload' => ['psr-4' => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: 'capell-app/marketplace-downloaded-action-package',
        surfaces: ['shared'],
        overrides: [
            'kind' => 'plugin',
            'displayName' => 'Marketplace Downloaded Action Package',
            'actions' => [
                'install' => LifecycleRecorderAction::class,
            ],
        ],
    ), $packagePath));

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/marketplace-downloaded-action-package',
        'extension_slug' => 'marketplace-downloaded-action-package',
        'extension_name' => 'Marketplace Downloaded Action Package',
        'user_id' => (string) $admin->getKey(),
    ]);

    app()->instance(MarketplaceComposerRunner::class, new class implements MarketplaceComposerRunner
    {
        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            throw new RuntimeException('Composer should not run for a downloaded package.');
        }
    });

    try {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and($attempt->failure_reason)->toBeNull()
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_composer_skipped_downloaded'))->exists())->toBeTrue()
        ->and(LifecycleRecorderAction::$calls)->toBe([
            [
                'package' => 'capell-app/marketplace-downloaded-action-package',
                'arguments' => [],
            ],
        ]);
});

it('marks composer success as failed when the installed package is not discovered by Capell', function (): void {
    Notification::fake();

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/not-discovered-suite',
        'extension_slug' => 'not-discovered-suite',
        'extension_name' => 'Not Discovered Suite',
    ]);

    app()->instance(MarketplaceComposerRunner::class, new class implements MarketplaceComposerRunner
    {
        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            return new MarketplaceComposerResultData(
                exitCode: 0,
                output: 'Composer installed the package files.',
                errorOutput: '',
            );
        }
    });

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));

    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_reason)->toBe('Installed package [capell-app/not-discovered-suite] was not discovered by Capell.')
        ->and($attempt->failure_type)->toBe(MarketplaceInstallFailureType::PackageNotDiscovered->value)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::PackageDiscovery->value)
        ->and($attempt->output_excerpt)->toBe('Composer installed the package files.')
        ->and($attempt->resolved_at)->toBeNull()
        ->and($attempt->events()->where('stage', MarketplaceInstallFailureStage::PackageDiscovery->value)->exists())->toBeTrue();
});

it('marks active attempts failed without sending the old failure notification when the queue exhausts the job', function (): void {
    Notification::fake();
    $superAdmin = test()->createUserWithRole('super_admin');
    $attempt = marketplaceOperationAttempt();

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->failed(
        new RuntimeException('The job has been attempted too many times.'),
    );

    expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_reason)->toBe('The job has been attempted too many times.')
        ->and($attempt->completed_at)->not->toBeNull()
        ->and($attempt->resolved_at)->toBeNull();

    Notification::assertNothingSentTo($superAdmin);
});

it('does not let stale queued cancellation overwrite running attempts', function (): void {
    $attempt = marketplaceOperationAttempt();
    $staleQueuedAttempt = MarketplaceInstallAttempt::query()->findOrFail($attempt->getKey());

    $attempt->forceFill(['status' => MarketplaceInstallIntentStatus::Running])->save();

    $cancelled = CancelMarketplaceInstallAttemptAction::run($staleQueuedAttempt);

    expect($cancelled->status)->toBe(MarketplaceInstallIntentStatus::CancelRequested)
        ->and($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::CancelRequested);
});

it('cancels queued attempts before composer starts', function (): void {
    $attempt = marketplaceOperationAttempt([
        'status' => MarketplaceInstallIntentStatus::CancelRequested,
        'cancel_requested_at' => now(),
    ]);

    app()->instance(MarketplaceComposerRunner::class, new class implements MarketplaceComposerRunner
    {
        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            throw new RuntimeException('Composer should not run.');
        }
    });

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));

    expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
        ->and($attempt->cancelled_at)->not->toBeNull();
});

it('keeps cancel-after-composer attempts unresolved for manual recovery', function (): void {
    Notification::fake();

    $attempt = marketplaceOperationAttempt([
        'status' => MarketplaceInstallIntentStatus::Queued,
    ]);

    app()->instance(MarketplaceComposerRunner::class, new readonly class((int) $attempt->getKey()) implements MarketplaceComposerRunner
    {
        public function __construct(private int $attemptId) {}

        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            MarketplaceInstallAttempt::query()
                ->whereKey($this->attemptId)
                ->update(['status' => MarketplaceInstallIntentStatus::CancelRequested]);

            return new MarketplaceComposerResultData(
                exitCode: 0,
                output: 'Package operations complete.',
                errorOutput: '',
            );
        }
    });

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));

    expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
        ->and($attempt->resolved_at)->toBeNull()
        ->and($attempt->failure_type)->toBe('cancelled_after_composer');
});

it('honours cancellation requested during lifecycle before atomically finalizing success', function (): void {
    Notification::fake();
    $admin = test()->createUserWithRole('super_admin');
    $packagePath = sys_get_temp_dir() . '/capell-marketplace-late-cancel-package-' . uniqid();

    File::ensureDirectoryExists($packagePath);
    File::put($packagePath . '/composer.json', json_encode([
        'name' => 'capell-app/marketplace-late-cancel-package',
        'autoload' => ['psr-4' => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: 'capell-app/marketplace-late-cancel-package',
        surfaces: ['shared'],
        overrides: [
            'kind' => 'plugin',
            'displayName' => 'Marketplace Late Cancel Package',
            'actions' => [
                'install' => CancelMarketplaceInstallDuringLifecycleAction::class,
            ],
        ],
    ), $packagePath));

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/marketplace-late-cancel-package',
        'extension_slug' => 'marketplace-late-cancel-package',
        'extension_name' => 'Marketplace Late Cancel Package',
        'user_id' => (string) $admin->getKey(),
    ]);
    CancelMarketplaceInstallDuringLifecycleAction::$attemptId = (int) $attempt->getKey();

    app()->instance(MarketplaceComposerRunner::class, new class implements MarketplaceComposerRunner
    {
        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            throw new RuntimeException('Composer should not run for a downloaded package.');
        }
    });

    try {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        CancelMarketplaceInstallDuringLifecycleAction::$attemptId = null;
        File::deleteDirectory($packagePath);
    }

    expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
        ->and($attempt->failure_type)->toBe(MarketplaceInstallFailureType::Unknown->value)
        ->and($attempt->resolved_at)->toBeNull()
        ->and($attempt->events()
            ->where('message', __('capell-marketplace::marketplace.operations.timeline_failed'))
            ->exists())->toBeFalse();

    Notification::assertNothingSentTo($admin);
});

function marketplaceOperationAttempt(array $overrides = []): MarketplaceInstallAttempt
{
    return MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/seo-suite',
        'extension_slug' => 'seo-suite',
        'extension_name' => 'SEO Suite',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Queued,
        'composer_command' => 'composer require capell-app/seo-suite:^2.1',
        'version_constraint' => '^2.1',
        'queued_at' => now(),
        ...$overrides,
    ]);
}

final class CancelMarketplaceInstallDuringLifecycleAction implements PackageLifecycleAction
{
    public static ?int $attemptId = null;

    public function handle(
        PackageData $package,
        array $arguments = [],
        ?ProgressReporter $reporter = null,
    ): void {
        throw_if(self::$attemptId === null, RuntimeException::class, 'The late-cancellation attempt was not configured.');

        $attempt = MarketplaceInstallAttempt::query()->findOrFail(self::$attemptId);

        CancelMarketplaceInstallAttemptAction::run($attempt);
        $reporter?->report('cancellation requested during lifecycle');
    }
}

it('restores the post-install state that --no-scripts suppresses', function (): void {
    Notification::fake();
    $admin = test()->createUserWithRole('super_admin');
    $packagePath = sys_get_temp_dir() . '/capell-marketplace-discovery-package-' . uniqid();

    File::ensureDirectoryExists($packagePath);
    File::put($packagePath . '/composer.json', json_encode([
        'name' => 'capell-app/marketplace-discovery-package',
        'autoload' => ['psr-4' => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'capell-app/marketplace-discovery-package',
        surfaces: ['shared'],
        overrides: [
            'kind' => 'plugin',
            'displayName' => 'Marketplace Discovery Package',
        ],
    ), $packagePath);
    File::put($packagePath . '/capell.json', json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/marketplace-discovery-package',
        'extension_slug' => 'marketplace-discovery-package',
        'extension_name' => 'Marketplace Discovery Package',
        'user_id' => (string) $admin->getKey(),
    ]);

    // Composer runs with --no-scripts, so the post-autoload-dump hook that
    // normally runs package:discover never fires. The job has to rebuild the
    // manifest itself or the extension's service provider never boots again.
    $packageManifest = new class(app(Filesystem::class), base_path(), base_path('bootstrap/cache/packages.php')) extends PackageManifest
    {
        public bool $rebuilt = false;

        public function build(): void
        {
            $this->rebuilt = true;
        }
    };

    app()->instance(PackageManifest::class, $packageManifest);

    // Rebuilding the Laravel manifest only covers package:discover. Everything
    // else the application declared for post-autoload-dump — asset publishing,
    // cache warming, whatever it happens to be — is suppressed too, and Capell
    // cannot enumerate it, so the application's own chain is replayed.
    $scriptRunner = new class implements MarketplaceComposerScriptRunner
    {
        /** @var list<array{event: string, timeout: int}> */
        public array $replayed = [];

        public function replayRootScript(string $event, int $timeoutSeconds): ?MarketplaceComposerResultData
        {
            $this->replayed[] = ['event' => $event, 'timeout' => $timeoutSeconds];

            return new MarketplaceComposerResultData(exitCode: 0, output: '', errorOutput: '');
        }
    };

    app()->instance(MarketplaceComposerScriptRunner::class, $scriptRunner);
    app()->instance(MarketplaceComposerRunner::class, new readonly class($manifest) implements MarketplaceComposerRunner
    {
        public function __construct(private CapellManifestData $manifest) {}

        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            CapellCore::registerManifestPackage($this->manifest);

            return new MarketplaceComposerResultData(exitCode: 0, output: 'Package installed.', errorOutput: '');
        }
    });

    try {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    expect($packageManifest->rebuilt)->toBeTrue()
        ->and($scriptRunner->replayed)->toHaveCount(1)
        ->and($scriptRunner->replayed[0]['event'])->toBe(MarketplaceComposerScriptRunner::POST_AUTOLOAD_DUMP)
        // The replay takes what is left of the job's own timeout. A budget of
        // its own — the composer timeout again — would have let the worker be
        // killed after the install was already applied.
        ->and($scriptRunner->replayed[0]['timeout'])
        ->toBeGreaterThan(RunMarketplaceInstallAttemptJob::composerTimeoutSeconds())
        ->and($scriptRunner->replayed[0]['timeout'])
        ->toBeLessThanOrEqual(
            RunMarketplaceInstallAttemptJob::jobTimeoutSeconds() - RunMarketplaceInstallAttemptJob::FINALISATION_RESERVE_SECONDS,
        )
        ->and($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Succeeded);
});

it('keeps a succeeded install succeeded when the runtime propagation throws', function (): void {
    Notification::fake();
    LifecycleRecorderAction::reset();
    $admin = test()->createUserWithRole('super_admin');
    $packagePath = sys_get_temp_dir() . '/capell-marketplace-propagation-package-' . uniqid();

    File::ensureDirectoryExists($packagePath);
    File::put($packagePath . '/composer.json', json_encode([
        'name' => 'capell-app/marketplace-propagation-package',
        'autoload' => ['psr-4' => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: 'capell-app/marketplace-propagation-package',
        surfaces: ['shared'],
        overrides: [
            'kind' => 'plugin',
            'displayName' => 'Marketplace Propagation Package',
            'actions' => [
                'install' => LifecycleRecorderAction::class,
            ],
        ],
    ), $packagePath));

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/marketplace-propagation-package',
        'extension_slug' => 'marketplace-propagation-package',
        'extension_name' => 'Marketplace Propagation Package',
        'user_id' => (string) $admin->getKey(),
    ]);

    app()->instance(MarketplaceComposerRunner::class, new class implements MarketplaceComposerRunner
    {
        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            throw new RuntimeException('Composer should not run for a downloaded package.');
        }
    });

    // A restricted opcache_reset() is the real instance of this: it raises an
    // E_WARNING that Laravel turns into an ErrorException, after the attempt is
    // already Succeeded. Nothing that happens after success may fail the install.
    app()->instance(PropagateMarketplaceRuntimeStateAction::class, new class
    {
        public function handle(MarketplaceInstallAttempt $attempt): ?string
        {
            throw new RuntimeException('opcache_reset() is restricted to a prefix on this host.');
        }
    });

    try {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
        app()->forgetInstance(PropagateMarketplaceRuntimeStateAction::class);
    }

    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and($attempt->failure_reason)->toBeNull()
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_runtime_propagation_failed'))->exists())->toBeTrue()
        // The operator is still told the install finished, which is the whole
        // point of not letting a post-success side effect fail the attempt.
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_notification_sent'))->exists())->toBeTrue();

    Notification::assertSentTo($admin, BroadcastNotification::class);
});

it('blames a missing queue worker when a stale queued attempt failed with no worker heartbeat', function (): void {
    MarketplaceWorkerHeartbeat::forget();

    $attempt = marketplaceOperationAttempt([
        'queued_at' => now()->subSeconds(FindStuckMarketplaceInstallOperationsAction::queuedStaleAfterSeconds() + 60),
    ]);

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())
        ->failed(new MaxAttemptsExceededException('Job has been attempted too many times.'));

    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_type)->toBe(MarketplaceInstallFailureType::QueueWorkerMissing->value)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Queue->value)
        ->and($attempt->failure_reason)->toBe(__('capell-marketplace::marketplace.operations.queue_worker_missing'))
        // Naming the missing worker must never cost the operator the error that
        // actually arrived.
        ->and($attempt->error_excerpt)->toContain('Job has been attempted too many times.');
});

it('does not blame the queue worker when a heartbeat proves one is consuming the queue', function (): void {
    // A queue backlog longer than the stale window: the job waited, a worker did
    // pick it up, and it threw before claiming the attempt. Age measures queue
    // wait, so only the heartbeat can tell those apart.
    MarketplaceWorkerHeartbeat::record();

    $attempt = marketplaceOperationAttempt([
        'queued_at' => now()->subSeconds(FindStuckMarketplaceInstallOperationsAction::queuedStaleAfterSeconds() + 60),
    ]);

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())
        ->failed(new RuntimeException('CAPELL_MULTI_NODE=true but the cache store is node local.'));

    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_type)->not->toBe(MarketplaceInstallFailureType::QueueWorkerMissing->value)
        ->and($attempt->failure_reason)->toBe('CAPELL_MULTI_NODE=true but the cache store is node local.');
});

it('does not blame the queue worker when lock contention exhausted the attempts of a released job', function (): void {
    // release() → backoff → attempts exhausted. The attempt never left Queued and
    // its age is well past the stale window, but a worker demonstrably ran.
    MarketplaceWorkerHeartbeat::record();

    $attempt = marketplaceOperationAttempt([
        'queued_at' => now()->subSeconds(FindStuckMarketplaceInstallOperationsAction::queuedStaleAfterSeconds() + 600),
    ]);

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())
        ->failed(new MaxAttemptsExceededException('Job has been attempted too many times.'));

    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_type)->not->toBe(MarketplaceInstallFailureType::QueueWorkerMissing->value)
        ->and($attempt->failure_reason)->toBe('Job has been attempted too many times.');
});

/**
 * Register a package whose install lifecycle action throws, so the job's
 * post-composer try block fails for a reason that is not a discovery failure.
 */
function registerFailingLifecyclePackage(string $composerName, string $displayName): string
{
    $packagePath = sys_get_temp_dir() . '/capell-marketplace-rollback-package-' . uniqid();

    File::ensureDirectoryExists($packagePath);
    File::put($packagePath . '/composer.json', json_encode([
        'name' => $composerName,
        'autoload' => ['psr-4' => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: $composerName,
        surfaces: ['shared'],
        overrides: [
            'kind' => 'plugin',
            'displayName' => $displayName,
            'actions' => ['install' => ThrowingMarketplaceLifecycleAction::class],
        ],
    ), $packagePath));

    return $packagePath;
}

function registerHealthyLifecyclePackage(string $composerName, string $displayName): string
{
    $packagePath = sys_get_temp_dir() . '/capell-marketplace-healthy-package-' . uniqid();

    File::ensureDirectoryExists($packagePath);
    File::put($packagePath . '/composer.json', json_encode([
        'name' => $composerName,
        'autoload' => ['psr-4' => []],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: $composerName,
        surfaces: ['shared'],
        overrides: [
            'kind' => 'plugin',
            'displayName' => $displayName,
            'actions' => ['install' => LifecycleRecorderAction::class],
        ],
    ), $packagePath));

    return $packagePath;
}

function refuseMarketplaceComposerRun(): void
{
    app()->instance(MarketplaceComposerRunner::class, new class implements MarketplaceComposerRunner
    {
        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            throw new RuntimeException('Composer should not run for a downloaded package.');
        }
    });
}

/** Records whether a rollback was asked for, without shelling out to Composer. */
function recordMarketplaceRollbacks(?Throwable $rollbackFailure = null): object
{
    $recorder = new class($rollbackFailure)
    {
        public int $calls = 0;

        public function __construct(private readonly ?Throwable $rollbackFailure) {}

        public function handle(ComposerStateSnapshot $snapshot, int $timeoutSeconds = 300): bool
        {
            $this->calls++;

            if ($this->rollbackFailure instanceof Throwable) {
                throw $this->rollbackFailure;
            }

            return true;
        }
    };

    app()->instance(RestoreComposerStateAction::class, $recorder);

    return $recorder;
}

function failMarketplaceHealthCheck(string $reason): void
{
    app()->instance(RunPostOperationHealthCheckAction::class, new readonly class($reason)
    {
        public function __construct(private string $reason) {}

        public function handle(int $budgetSeconds): MarketplaceHealthCheckResultData
        {
            return new MarketplaceHealthCheckResultData(
                bootProbe: MarketplaceHealthProbeOutcome::Failed,
                httpProbe: MarketplaceHealthProbeOutcome::Skipped,
                failureReason: $this->reason,
                bootProbeOutput: 'PHP Fatal error: Class "Missing\\Provider" not found',
            );
        }
    });
}

it('does not rebuild vendor when composer failed without changing the manifests', function (): void {
    // Composer restores composer.json itself when a require fails, so the lock
    // still describes what is installed. A recovery install here would spend
    // minutes reproducing the state that is already on disk.
    Notification::fake();
    $rollbacks = recordMarketplaceRollbacks();

    $attempt = marketplaceOperationAttempt();

    app()->instance(MarketplaceComposerRunner::class, new class implements MarketplaceComposerRunner
    {
        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            return new MarketplaceComposerResultData(exitCode: 1, output: '', errorOutput: 'Composer failed hard');
        }
    });

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));

    $attempt->refresh();

    expect($rollbacks->calls)->toBe(0)
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Composer->value)
        // The snapshot is still taken, because the operation that mutates the
        // manifests may be the lifecycle rather than Composer.
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_snapshot_captured'))->exists())
        ->toBeTrue();
});

it('rolls the composer state back when the extension lifecycle fails', function (): void {
    Notification::fake();
    $rollbacks = recordMarketplaceRollbacks();
    $packagePath = registerFailingLifecyclePackage('capell-app/marketplace-lifecycle-rollback', 'Lifecycle Rollback');
    refuseMarketplaceComposerRun();

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/marketplace-lifecycle-rollback',
        'extension_slug' => 'marketplace-lifecycle-rollback',
        'extension_name' => 'Lifecycle Rollback',
    ]);

    try {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    $attempt->refresh();

    expect($rollbacks->calls)->toBe(1)
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Lifecycle->value)
        ->and($attempt->failure_reason)->toContain('Lifecycle action refused this install.')
        // The whole promise of this task: the operator is told the site was put
        // back, not merely that something failed.
        ->and($attempt->context['rollback']['rolled_back'] ?? null)->toBeTrue()
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_rollback_completed'))->exists())
        ->toBeTrue();
});

it('rolls the composer state back when the post-install health check refuses the site', function (): void {
    Notification::fake();
    LifecycleRecorderAction::reset();
    $rollbacks = recordMarketplaceRollbacks();
    failMarketplaceHealthCheck('A fresh process could not boot this site.');
    $packagePath = registerHealthyLifecyclePackage('capell-app/marketplace-health-rollback', 'Health Rollback');
    refuseMarketplaceComposerRun();

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/marketplace-health-rollback',
        'extension_slug' => 'marketplace-health-rollback',
        'extension_name' => 'Health Rollback',
    ]);

    try {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    $attempt->refresh();

    expect($rollbacks->calls)->toBe(1)
        // The lifecycle completed and the package is installed — and the site is
        // still put back, because a site that cannot be confirmed working is not
        // a successful install.
        ->and(LifecycleRecorderAction::$calls)->toHaveCount(1)
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::HealthCheck->value)
        ->and($attempt->failure_type)->toBe(MarketplaceInstallFailureType::HealthCheckFailed->value)
        ->and($attempt->failure_reason)->toContain('A fresh process could not boot this site.')
        ->and($attempt->context['rollback']['rolled_back'] ?? null)->toBeTrue()
        // The probe's own verdict is recorded separately from the operation's,
        // so an operator can tell a broken site from a probe that could not run.
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_health_check_probe_failed'))->exists())
        ->toBeTrue()
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_rollback_completed'))->exists())
        ->toBeTrue();

    // Nothing announced a success that did not happen.
    Notification::assertNothingSent();
});

it('records both the original failure and the rollback failure when recovery itself fails', function (): void {
    // The worst case. A rollback failure that replaced the original cause would
    // leave the operator repairing a host without knowing what broke it.
    Notification::fake();
    $rollbacks = recordMarketplaceRollbacks(new RuntimeException('Composer could not restore the package installation automatically.'));
    $packagePath = registerFailingLifecyclePackage('capell-app/marketplace-rollback-failure', 'Rollback Failure');
    refuseMarketplaceComposerRun();

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/marketplace-rollback-failure',
        'extension_slug' => 'marketplace-rollback-failure',
        'extension_name' => 'Rollback Failure',
    ]);

    try {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    $attempt->refresh();
    $rollbackFailedEvent = $attempt->events()
        ->where('message', __('capell-marketplace::marketplace.operations.timeline_rollback_failed'))
        ->firstOrFail();

    expect($rollbacks->calls)->toBe(1)
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        // A rollback that did not complete outranks whatever caused it: the
        // operator's next action is no longer "read the error", it is "repair
        // this host".
        ->and($attempt->failure_type)->toBe(MarketplaceInstallFailureType::RollbackFailed->value)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Lifecycle->value)
        // BOTH errors, and a plain instruction to recover by hand.
        ->and($attempt->failure_reason)->toContain('Lifecycle action refused this install.')
        ->and($attempt->failure_reason)->toContain('Composer could not restore the package installation automatically.')
        ->and($attempt->failure_reason)->toContain('composer install --no-interaction --no-scripts')
        ->and($attempt->context['rollback']['rolled_back'] ?? null)->toBeFalse()
        ->and($attempt->context['rollback']['error'] ?? null)->toContain('Lifecycle action refused this install.')
        ->and($attempt->context['rollback']['rollback_error'] ?? null)->toContain('could not restore the package installation')
        ->and($rollbackFailedEvent->context['error'] ?? null)->toContain('Lifecycle action refused this install.')
        ->and($rollbackFailedEvent->context['rollback_error'] ?? null)->toContain('could not restore the package installation');
});

it('runs the health check before success is declared and never after it', function (): void {
    // Succeeded is terminal — ALLOWED_TRANSITIONS has no way out of it — so a
    // check that ran after finalisation could only crash a completed install.
    Notification::fake();
    LifecycleRecorderAction::reset();
    $packagePath = registerHealthyLifecyclePackage('capell-app/marketplace-health-order', 'Health Order');
    refuseMarketplaceComposerRun();

    $observedStatuses = [];

    app()->instance(RunPostOperationHealthCheckAction::class, new class($observedStatuses)
    {
        /** @param array<int, string> $observedStatuses */
        public function __construct(public array &$observedStatuses) {}

        public function handle(int $budgetSeconds): MarketplaceHealthCheckResultData
        {
            $this->observedStatuses = MarketplaceInstallAttempt::query()
                ->pluck('status')
                ->map(fn (mixed $status): string => $status instanceof BackedEnum ? (string) $status->value : (string) $status)
                ->all();

            return new MarketplaceHealthCheckResultData(
                bootProbe: MarketplaceHealthProbeOutcome::Passed,
                httpProbe: MarketplaceHealthProbeOutcome::Skipped,
            );
        }
    });

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/marketplace-health-order',
        'extension_slug' => 'marketplace-health-order',
        'extension_name' => 'Health Order',
    ]);

    try {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    expect(resolve(RunPostOperationHealthCheckAction::class)->observedStatuses)
        ->toBe([MarketplaceInstallIntentStatus::Running->value])
        ->and($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_health_check_completed'))->exists())
        ->toBeTrue();
});

final class ThrowingMarketplaceLifecycleAction implements PackageLifecycleAction
{
    public function handle(
        PackageData $package,
        array $arguments = [],
        ?ProgressReporter $reporter = null,
    ): void {
        throw new RuntimeException('Lifecycle action refused this install.');
    }
}

it('offers a theme install a one-click way to apply the theme', function (): void {
    // An install that finished is not an install that is doing anything. A
    // theme still has to be applied, and making the operator go and find that
    // surface is the step most theme installs stall on.
    Notification::fake();
    $admin = test()->createUserWithRole('super_admin');

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/aurora-theme',
        'extension_slug' => 'aurora-theme',
        'extension_name' => 'Aurora',
        'kind' => 'theme',
        'user_id' => (string) $admin->getKey(),
    ]);

    NotifyMarketplaceInstallCompletedAction::run($attempt);

    Notification::assertSentTo(
        $admin,
        BroadcastNotification::class,
        fn (BroadcastNotification $notification): bool => ($notification->data['actions'][0]['label'] ?? null)
            === (string) __('capell-marketplace::marketplace.install.activate_theme_action')
            // The theme's own page, which is where the existing
            // ApplyMarketplaceThemeToSitesAction flow already lives.
            && str_contains((string) ($notification->data['actions'][0]['url'] ?? ''), 'aurora'),
    );
});

it('keeps sending a non-theme install to the settings surface it already has', function (): void {
    Notification::fake();
    $admin = test()->createUserWithRole('super_admin');

    $attempt = marketplaceOperationAttempt(['user_id' => (string) $admin->getKey()]);

    NotifyMarketplaceInstallCompletedAction::run($attempt);

    Notification::assertSentTo(
        $admin,
        BroadcastNotification::class,
        fn (BroadcastNotification $notification): bool => ($notification->data['actions'][0]['label'] ?? null)
            === (string) __('capell-marketplace::marketplace.install.installed_action'),
    );
});
