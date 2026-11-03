<?php

declare(strict_types=1);

use Capell\Core\Actions\BuildPackageCacheAction;
use Capell\Core\Actions\Extensions\VerifyInstalledExtensionAction;
use Capell\Core\Data\Extensions\ExtensionVerificationIssueData;
use Capell\Core\Data\Extensions\ExtensionVerificationReportData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\ExtensionQuarantine;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Composer\ComposerStateSnapshot;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Tests\Support\Fixtures\Autoload\LifecycleRecorderAction;
use Capell\Marketplace\Actions\CancelMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\FindStuckMarketplaceInstallOperationsAction;
use Capell\Marketplace\Actions\NotifyMarketplaceInstallCompletedAction;
use Capell\Marketplace\Actions\PropagateMarketplaceRuntimeStateAction;
use Capell\Marketplace\Actions\QueryMarketplaceInstallProgressAction;
use Capell\Marketplace\Actions\RecordThemeInstallIntentAction;
use Capell\Marketplace\Actions\RestoreComposerStateAction;
use Capell\Marketplace\Actions\RunPostOperationHealthCheckAction;
use Capell\Marketplace\Contracts\MarketplaceAuthenticatedComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceComposerScriptRunner;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Data\MarketplaceHealthCheckResultData;
use Capell\Marketplace\Data\MarketplaceInstallProgressData;
use Capell\Marketplace\Data\MarketplaceInstallProgressQueryData;
use Capell\Marketplace\Enums\MarketplaceHealthProbeOutcome;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceInstallNotifications;
use Capell\Marketplace\Support\MarketplaceWorkerHeartbeat;
use Capell\Marketplace\Tests\Support\CancelMarketplaceInstallDuringLifecycleAction;
use Capell\Marketplace\Tests\Support\MarketplaceRollbackRecorder;
use Capell\Marketplace\Tests\Support\RecordingMarketplaceComposerRunner;
use Capell\Marketplace\Tests\Support\StatusRecordingPostOperationHealthCheckAction;
use Capell\Marketplace\Tests\Support\ThrowingMarketplaceLifecycleAction;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Filament\Notifications\BroadcastNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 5) . '/tests/Support/InstallFilesystemLock.php';

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    // These tests exercise the later lifecycle with synthetic packages, rather
    // than a Composer-installed distribution on disk. The job verifies with the
    // release's constraint, and the install action then verifies by name alone.
    VerifyInstalledExtensionAction::shouldRun()->byDefault()->andReturnUsing(
        fn (string $name, ?string $constraint = null, ?string $previous = null): ExtensionVerificationReportData => new ExtensionVerificationReportData(
            $name,
            '3.0.0',
            $constraint,
            $previous,
            true,
            [],
            [],
            CarbonImmutable::now(),
        ),
    );
    preserveTestbenchPackageManifestFilesDuringPackageRemoval();

    // Worker tests reload Composer; retain the web readers from this checkout.
    class_exists(QueryMarketplaceInstallProgressAction::class);
    class_exists(MarketplaceInstallProgressData::class);
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

    $composer = new RecordingMarketplaceComposerRunner;
    app()->instance(MarketplaceComposerRunner::class, $composer);

    expect(function () use ($attempt, $composer): void {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle($composer);
    })->toThrow(RuntimeException::class, 'CAPELL_MULTI_NODE=true');

    expect($composer->ran())->toBeFalse()
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
    $packageManifest = new class(resolve(Filesystem::class), base_path(), base_path('bootstrap/cache/packages.php')) extends PackageManifest
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

        public function replayRootScript(string $event, int $timeoutSeconds): MarketplaceComposerResultData
        {
            $this->replayed[] = ['event' => $event, 'timeout' => $timeoutSeconds];

            return new MarketplaceComposerResultData(exitCode: 0, output: '', errorOutput: '');
        }
    };

    app()->instance(MarketplaceComposerScriptRunner::class, $scriptRunner);
    BuildPackageCacheAction::shouldRun()
        ->once()
        ->withArgs(fn (array $manifests): bool => isset(
            $manifests['capell-app/core'],
            $manifests['capell-app/marketplace'],
        ));
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

    expect(QueryMarketplaceInstallProgressAction::run(
        MarketplaceInstallProgressQueryData::forAttemptIds([(int) $attempt->getKey()]),
    )->items[0]->isActive())->toBeTrue();

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

    $progress = QueryMarketplaceInstallProgressAction::run(
        MarketplaceInstallProgressQueryData::forAttemptIds([(int) $attempt->getKey()]),
    )->items[0];
    expect($progress->runtimeRefreshRequired)->toBeTrue()
        ->and($progress->isActive())->toBeFalse();

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
function recordMarketplaceRollbacks(?Throwable $rollbackFailure = null): MarketplaceRollbackRecorder
{
    $recorder = new MarketplaceRollbackRecorder($rollbackFailure);

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
    $packageManifest = new class(resolve(Filesystem::class), base_path(), base_path('bootstrap/cache/packages.php')) extends PackageManifest
    {
        public bool $rebuilt = false;

        public function build(): void
        {
            $this->rebuilt = true;
        }
    };
    app()->instance(PackageManifest::class, $packageManifest);
    BuildPackageCacheAction::shouldRun()->twice();
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
        ->toBeTrue()
        ->and($packageManifest->rebuilt)->toBeTrue()
        ->and(CapellCore::hasPackage('capell-app/marketplace-lifecycle-rollback'))->toBeFalse()
        // The snapshot and the rollback budget are the two arguments the
        // recovery actually depends on, and PHP would let the job pass neither
        // without a murmur, so they are asserted rather than assumed.
        ->and($rollbacks->snapshots[0])->toBeInstanceOf(ComposerStateSnapshot::class)
        ->and($rollbacks->timeoutSeconds[0])->toBeGreaterThan(0);
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

    $healthCheck = new StatusRecordingPostOperationHealthCheckAction;
    app()->instance(RunPostOperationHealthCheckAction::class, $healthCheck);

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

    expect($healthCheck->observedStatuses)
        ->toBe([MarketplaceInstallIntentStatus::Running->value])
        ->and($healthCheck->observedBudgetSeconds)->toBeGreaterThan(0)
        ->and($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_health_check_completed'))->exists())
        ->toBeTrue();
});

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

/** Hand the job back the real health check, which MarketplaceTestCase stubs out. */
function useRealMarketplaceHealthCheck(): void
{
    app()->instance(RunPostOperationHealthCheckAction::class, new RunPostOperationHealthCheckAction);
}

it('completes a slow install unverified instead of rolling it back', function (): void {
    // The failure this guards against: a job that spent its budget leaves the
    // health check nothing, the probe times out, and a perfectly good install is
    // condemned and rolled back for being slow.
    Notification::fake();
    LifecycleRecorderAction::reset();
    useRealMarketplaceHealthCheck();
    $rollbacks = recordMarketplaceRollbacks();

    // A job budget smaller than the finalisation reserve, so nothing is left for
    // the health check by the time it is asked.
    config()->set('capell.process.composer.timeout_seconds', 1);
    config()->set('capell.process.composer.job_timeout_buffer_seconds', 1);

    $probeFactory = Mockery::mock(ProcessFactoryInterface::class);
    $probeFactory->shouldReceive('make')->never();
    app()->instance(ProcessFactoryInterface::class, $probeFactory);

    $packagePath = registerHealthyLifecyclePackage('capell-app/marketplace-health-nobudget', 'Health No Budget');
    refuseMarketplaceComposerRun();

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/marketplace-health-nobudget',
        'extension_slug' => 'marketplace-health-nobudget',
        'extension_name' => 'Health No Budget',
    ]);

    try {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    $attempt->refresh();

    expect($rollbacks->calls)->toBe(0)
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and($attempt->failure_stage)->toBeNull()
        // And the operator can see it was never confirmed, rather than reading a
        // pass that nobody actually observed.
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_health_check_skipped'))->exists())
        ->toBeTrue()
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_health_check_completed'))->exists())
        ->toBeFalse();
});

it('still rolls back when a probe that had time to run reports the site as broken', function (): void {
    Notification::fake();
    LifecycleRecorderAction::reset();
    useRealMarketplaceHealthCheck();
    $rollbacks = recordMarketplaceRollbacks();

    $artisanPath = base_path('artisan');
    $artisanExisted = File::exists($artisanPath);

    if (! $artisanExisted) {
        File::put($artisanPath, "<?php\n");
    }

    $probe = Mockery::mock(Process::class);
    $probe->shouldReceive('setTimeout')->andReturnSelf();
    $probe->shouldReceive('run')->andReturn(0);
    $probe->shouldReceive('getExitCode')->andReturn(255);
    $probeFactory = Mockery::mock(ProcessFactoryInterface::class);
    $probeFactory->shouldReceive('make')->andReturn($probe);
    app()->instance(ProcessFactoryInterface::class, $probeFactory);

    $packagePath = registerHealthyLifecyclePackage('capell-app/marketplace-health-real', 'Health Real');
    refuseMarketplaceComposerRun();

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/marketplace-health-real',
        'extension_slug' => 'marketplace-health-real',
        'extension_name' => 'Health Real',
    ]);

    try {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);

        if (! $artisanExisted) {
            File::delete($artisanPath);
        }
    }

    $attempt->refresh();

    expect($rollbacks->calls)->toBe(1)
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::HealthCheck->value)
        ->and($attempt->failure_type)->toBe(MarketplaceInstallFailureType::HealthCheckFailed->value);
});

it('restores the composer state when the composer run was cut short by a timeout', function (): void {
    // A clean require failure reverts composer.json itself. A timeout does not:
    // the process was killed, so composer.json can be left rewritten with
    // vendor/ half-populated — the exact inconsistency the snapshot exists for.
    Notification::fake();
    $rollbacks = recordMarketplaceRollbacks();

    $attempt = marketplaceOperationAttempt();

    app()->instance(MarketplaceComposerRunner::class, new class implements MarketplaceComposerRunner
    {
        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            return new MarketplaceComposerResultData(exitCode: 1, output: '', errorOutput: 'killed', timedOut: true);
        }
    });

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));

    $attempt->refresh();

    expect($rollbacks->calls)->toBe(1)
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::TimedOut)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Composer->value)
        ->and($attempt->context['rollback']['rolled_back'] ?? null)->toBeTrue()
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_rollback_completed'))->exists())
        ->toBeTrue();
});

it('applies the theme after install when the operator asked for it on the review screen', function (): void {
    Notification::fake();
    LifecycleRecorderAction::reset();
    $packagePath = registerHealthyLifecyclePackage('capell-app/aurora-theme', 'Aurora');
    refuseMarketplaceComposerRun();

    RecordThemeInstallIntentAction::run(
        extensionSlug: 'aurora-theme',
        extensionName: 'Aurora',
        composerName: 'capell-app/aurora-theme',
        composerCommand: 'composer require capell-app/aurora-theme',
        versionConstraint: '^1.0',
        imageUrl: null,
        description: null,
        metadata: [RecordThemeInstallIntentAction::ACTIVATE_AFTER_INSTALL => true],
    );

    $site = Site::factory()->create();

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/aurora-theme',
        'extension_slug' => 'aurora-theme',
        'extension_name' => 'Aurora',
        'kind' => 'theme',
        'user_id' => (string) test()->createUserWithRole('super_admin')->getKey(),
    ]);

    try {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    $attempt->refresh();
    $theme = Theme::query()->where('key', 'aurora')->first();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and($theme)->not->toBeNull()
        ->and($theme?->status)->toBeTrue()
        ->and($site->refresh()->theme_id)->toBe($theme?->getKey())
        ->and($attempt->events()->where('message', __('capell-marketplace::marketplace.operations.timeline_theme_activated'))->exists())
        ->toBeTrue();
});

it('leaves the sites alone when the operator did not ask for the theme to be applied', function (): void {
    Notification::fake();
    LifecycleRecorderAction::reset();
    $packagePath = registerHealthyLifecyclePackage('capell-app/nebula-theme', 'Nebula');
    refuseMarketplaceComposerRun();

    RecordThemeInstallIntentAction::run(
        extensionSlug: 'nebula-theme',
        extensionName: 'Nebula',
        composerName: 'capell-app/nebula-theme',
        composerCommand: 'composer require capell-app/nebula-theme',
        versionConstraint: '^1.0',
        imageUrl: null,
        description: null,
        metadata: [RecordThemeInstallIntentAction::ACTIVATE_AFTER_INSTALL => false],
    );

    $attempt = marketplaceOperationAttempt([
        'composer_name' => 'capell-app/nebula-theme',
        'extension_slug' => 'nebula-theme',
        'extension_name' => 'Nebula',
        'kind' => 'theme',
    ]);

    try {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
    } finally {
        File::deleteDirectory($packagePath);
    }

    expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and(Theme::query()->where('key', 'nebula')->exists())->toBeFalse();
});

it('rolls back a failed verification before discovery or health checks and stores a safe report', function (): void {
    Notification::fake();
    $rollbacks = recordMarketplaceRollbacks();
    $health = new StatusRecordingPostOperationHealthCheckAction;
    app()->instance(RunPostOperationHealthCheckAction::class, $health);
    $checksum = str_repeat('a', 64);
    $contentHash = str_repeat('b', 64);
    $attempt = marketplaceOperationAttempt(['composer_name' => 'vendor/verification-fixture', 'extension_slug' => 'verification-fixture', 'context' => ['release_checksum' => $checksum, 'release_content_sha256' => $contentHash]]);
    $runner = new RecordingMarketplaceComposerRunner('installed');
    $issue = new ExtensionVerificationIssueData('checksum_mismatch', '/private/path', '/private/path');
    $report = new ExtensionVerificationReportData($attempt->composer_name, '2.2.0', '^2.1', null, false, [$issue], [], CarbonImmutable::now(), installPath: '/private/path');
    VerifyInstalledExtensionAction::shouldRun()->once()->with($attempt->composer_name, '^2.1', null, 'verification-fixture', $checksum, null, $contentHash)->andReturn($report);

    new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle($runner);

    $attempt->refresh();
    $message = __('capell-marketplace::marketplace.operations.verification_quarantined', [
        'package' => $attempt->composer_name,
        'packages' => $attempt->composer_name,
        'reason' => __('capell-marketplace::marketplace.operations.verification_failed', [
            'message' => __('capell::extensions.verification.checksum_mismatch.message', ['package' => $attempt->composer_name]),
            'action' => __('capell::extensions.verification.checksum_mismatch.next_action', ['package' => $attempt->composer_name]),
        ]),
    ]);
    expect($runner->ran())->toBeTrue()
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Verification->value)
        ->and($attempt->failure_reason)->toBe($message)
        ->and($attempt->failure_reason)->not->toContain('/private/path')
        ->and($rollbacks->calls)->toBe(1)
        ->and($attempt->context['rollback']['rolled_back'] ?? null)->toBeTrue()
        ->and($attempt->context['verification_report']['passed'] ?? null)->toBeFalse()
        ->and($attempt->context['verification_report']['failures'][0] ?? null)->toEqual([
            'code' => 'checksum_mismatch',
            'message' => __('capell::extensions.verification.checksum_mismatch.message', ['package' => $attempt->composer_name]),
            'next_action' => __('capell::extensions.verification.checksum_mismatch.next_action', ['package' => $attempt->composer_name]),
        ])
        ->and(json_encode($attempt->context['verification_report'] ?? []))->not->toContain('/private/path')
        ->and($health->observedStatuses)->toBeEmpty()
        ->and($attempt->events()->where('stage', MarketplaceInstallFailureStage::PackageDiscovery->value)->exists())->toBeFalse()
        ->and($attempt->events()->where('stage', MarketplaceInstallFailureStage::Lifecycle->value)->exists())->toBeFalse()
        ->and($attempt->events()->where('stage', MarketplaceInstallFailureStage::Verification->value)->exists())->toBeTrue();
});

it('continues from a passing verification to the health check', function (): void {
    Notification::fake();
    $path = registerHealthyLifecyclePackage('capell-app/verified-package', 'Verified Package');
    refuseMarketplaceComposerRun();
    $health = new StatusRecordingPostOperationHealthCheckAction;
    app()->instance(RunPostOperationHealthCheckAction::class, $health);
    $attempt = marketplaceOperationAttempt(['composer_name' => 'capell-app/verified-package', 'extension_slug' => 'verified-package']);
    VerifyInstalledExtensionAction::shouldRun()->once()->with('capell-app/verified-package', '^2.1', null, 'verified-package', null, null)
        ->andReturn(new ExtensionVerificationReportData('capell-app/verified-package', '2.2.0', '^2.1', null, true, [], [], CarbonImmutable::now()));
    try {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(resolve(MarketplaceComposerRunner::class));
        expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
            ->and($attempt->context['verification_report']['passed'] ?? null)->toBeTrue()
            ->and($health->observedStatuses)->toBe([MarketplaceInstallIntentStatus::Running->value]);
    } finally {
        File::deleteDirectory($path);
    }
});

it('restores Composer state and stops before lifecycle work when cancelled during verification', function (): void {
    Notification::fake();
    $health = new StatusRecordingPostOperationHealthCheckAction;
    app()->instance(RunPostOperationHealthCheckAction::class, $health);
    $attempt = marketplaceOperationAttempt();
    VerifyInstalledExtensionAction::shouldRun()->once()->andReturnUsing(function (string $name) use ($attempt): ExtensionVerificationReportData {
        MarketplaceInstallAttempt::query()->whereKey($attempt->getKey())->update(['status' => MarketplaceInstallIntentStatus::CancelRequested]);

        return new ExtensionVerificationReportData($name, '2.2.0', '^2.1', null, true, [], [], CarbonImmutable::now());
    });

    withMarketplaceVerificationTree([], [], function (string $root, string $composerBefore, string $lockBefore) use ($attempt, $health): void {
        $runner = marketplaceFixtureComposerRunner($root, [], []);
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle($runner);

        expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
            ->and(file_get_contents($root . '/composer.json'))->toBe($composerBefore)
            ->and(file_get_contents($root . '/composer.lock'))->toBe($lockBefore)
            ->and($health->observedStatuses)->toBeEmpty()
            ->and($attempt->events()->where('stage', MarketplaceInstallFailureStage::Lifecycle->value)->exists())->toBeFalse()
            ->and($attempt->events()->where('stage', MarketplaceInstallFailureStage::PackageDiscovery->value)->exists())->toBeFalse();
    });
});

/**
 * Run against an isolated application root and a real file restore. Only the
 * recovery subprocess is faked; no Composer process is started by these tests.
 *
 * @param  list<array<string, mixed>>  $before
 * @param  list<array<string, mixed>>  $installed
 */
function withMarketplaceVerificationTree(array $before, array $installed, Closure $test): void
{
    $previousBase = app()->basePath();
    $root = sys_get_temp_dir() . '/marketplace-verification-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($root . '/vendor/composer');
    // The lifecycle stage refreshes the runtime manifests under bootstrap/cache and storage, so give the relocated root both.
    File::ensureDirectoryExists($root . '/bootstrap/cache');
    File::ensureDirectoryExists($root . '/storage/framework/cache');
    $composerBefore = "{\n  \"require\": {}\n}\n";
    $lockBefore = json_encode(['packages' => $before, 'packages-dev' => []], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
    File::put($root . '/composer.json', $composerBefore);
    File::put($root . '/composer.lock', $lockBefore);
    File::put($root . '/vendor/composer/installed.json', json_encode(['packages' => $installed], JSON_THROW_ON_ERROR));
    app()->setBasePath($root);

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setEnv')->andReturnSelf();
    $process->shouldReceive('setTimeout')->andReturnSelf();
    $process->shouldReceive('run')->andReturn(0);
    $process->shouldReceive('isSuccessful')->andReturnTrue();
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);
    app()->instance(PackageManifest::class, new class(resolve(Filesystem::class), $root, $root . '/packages.php') extends PackageManifest
    {
        public function build(): void {}
    });
    app()->instance(MarketplaceComposerScriptRunner::class, new class implements MarketplaceComposerScriptRunner
    {
        public function replayRootScript(string $event, int $timeoutSeconds): MarketplaceComposerResultData
        {
            return new MarketplaceComposerResultData(exitCode: 0, output: '', errorOutput: '');
        }
    });

    try {
        $test($root, $composerBefore, $lockBefore);
    } finally {
        app()->setBasePath($previousBase);
        File::deleteDirectory($root);
    }
}

/** @param list<array<string, mixed>> $after
 * @param  list<array<string, mixed>>  $installed
 */
function marketplaceFixtureComposerRunner(string $root, array $after, array $installed, ?Closure $onRequire = null): MarketplaceComposerRunner
{
    return new readonly class($root, $after, $installed, $onRequire) implements MarketplaceComposerRunner
    {
        public function __construct(private string $root, private array $after, private array $installed, private ?Closure $onRequire) {}

        public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
        {
            File::put($this->root . '/composer.json', '{"require":{"vendor/changed":"^1.0"}}');
            File::put($this->root . '/composer.lock', json_encode(['packages' => $this->after, 'packages-dev' => []], JSON_THROW_ON_ERROR));
            File::put($this->root . '/vendor/composer/installed.json', json_encode(['packages' => $this->installed], JSON_THROW_ON_ERROR));
            ($this->onRequire)?->__invoke();

            return new MarketplaceComposerResultData(exitCode: 0, output: 'installed', errorOutput: '');
        }
    };
}

it('bounds a real verification report and restores both Composer files', function (): void {
    Notification::fake();
    $name = 'vendor/bounded-fixture';
    $package = ['name' => $name, 'version' => '2.2.0', 'type' => 'library', 'install-path' => '../vendor/bounded-fixture', 'autoload' => ['files' => array_fill(0, 1000, '../outside.php')]];
    $attempt = marketplaceOperationAttempt(['composer_name' => $name, 'extension_slug' => 'bounded-fixture']);

    withMarketplaceVerificationTree([], [], function (string $root, string $composerBefore, string $lockBefore) use ($attempt, $package, $name): void {
        $path = $root . '/vendor/vendor/bounded-fixture';
        File::ensureDirectoryExists($path);
        $manifest = json_decode(File::get(__DIR__ . '/../../../../core/tests/fixtures/manifest-v3/valid-premium-package.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['name'] = $name;
        $manifest['slug'] = 'bounded-fixture';
        $manifest['providers']['runtime'] = [];
        $manifest['contributes'] = [];
        $manifest['healthChecks'] = [];
        File::put($path . '/capell.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        app()->instance(VerifyInstalledExtensionAction::class, new VerifyInstalledExtensionAction);

        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(marketplaceFixtureComposerRunner($root, [$package], [$package]));

        $attempt->refresh();
        $report = $attempt->context['verification_report'] ?? [];
        $event = $attempt->events()->where('stage', MarketplaceInstallFailureStage::Verification->value)->where('message', __('capell-marketplace::marketplace.operations.timeline_verification_failed'))->firstOrFail();
        expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
            ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Verification->value)
            ->and($report['failures'] ?? null)->toHaveCount(50)
            ->and($report['omitted_issue_count'] ?? null)->toBe(950)
            ->and($event?->context['verification_report']['failures'] ?? null)->toHaveCount(50)
            ->and($event?->context['verification_report']['omitted_issue_count'] ?? null)->toBe(950)
            ->and(file_get_contents($root . '/composer.json'))->toBe($composerBefore)
            ->and(file_get_contents($root . '/composer.lock'))->toBe($lockBefore);
    });
});

it('rejects a newly added Composer plugin dependency and restores the snapshot', function (): void {
    Notification::fake();
    $dependency = ['name' => 'vendor/new-plugin', 'version' => '1.0.0', 'type' => 'composer-plugin', 'install-path' => '../vendor/new-plugin'];
    $attempt = marketplaceOperationAttempt();
    withMarketplaceVerificationTree([], [], function (string $root, string $composerBefore, string $lockBefore) use ($attempt, $dependency): void {
        File::ensureDirectoryExists($root . '/vendor/vendor/new-plugin');
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(marketplaceFixtureComposerRunner($root, [$dependency], [$dependency]));
        $attempt->refresh();
        $report = $attempt->context['verification_report'] ?? [];

        expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
            ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Verification->value)
            ->and($report['composer_name'] ?? null)->toBe('vendor/new-plugin')
            ->and($report['failures'][0]['code'])->toBe('composer_plugin')
            ->and(json_encode($attempt->context, JSON_THROW_ON_ERROR))->not->toContain($root)
            ->and(file_get_contents($root . '/composer.json'))->toBe($composerBefore)
            ->and(file_get_contents($root . '/composer.lock'))->toBe($lockBefore);
    });
});

it('quarantines the whole changed set when an added dependency fails and lifts it after a completed rollback', function (): void {
    Notification::fake();
    $name = 'vendor/verified-top-level';
    $dependency = ['name' => 'vendor/tampered-dependency', 'version' => '1.0.0', 'type' => 'composer-plugin', 'install-path' => '../vendor/tampered-dependency'];
    $attempt = marketplaceOperationAttempt(['composer_name' => $name, 'extension_slug' => 'verified-top-level']);
    VerifyInstalledExtensionAction::shouldRun()->andReturn(new ExtensionVerificationReportData($name, '1.0.0', null, null, true, [], [], CarbonImmutable::now()));
    // Observe the quarantine while the rollback runs: it must cover the whole set until the rollback completes.
    $rollbacks = new class
    {
        public int $calls = 0;

        /** @var list<string>|null */
        public ?array $quarantinedDuringRollback = null;

        public function handle(ComposerStateSnapshot $snapshot, int $timeoutSeconds = ComposerStateSnapshot::DEFAULT_TIMEOUT_SECONDS): bool
        {
            $this->calls++;
            $this->quarantinedDuringRollback = array_keys(ExtensionQuarantine::quarantinedNames());

            return true;
        }
    };
    app()->instance(RestoreComposerStateAction::class, $rollbacks);

    withMarketplaceVerificationTree([], [], function (string $root) use ($attempt, $dependency): void {
        File::ensureDirectoryExists($root . '/vendor/vendor/tampered-dependency');
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(marketplaceFixtureComposerRunner($root, [$dependency], [$dependency]));
    });

    $attempt->refresh();
    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Verification->value)
        ->and($attempt->failure_reason)->toContain(__('capell-marketplace::marketplace.operations.verification_quarantined', [
            'package' => 'vendor/tampered-dependency',
            'packages' => 'vendor/tampered-dependency, vendor/verified-top-level',
            'reason' => '',
        ]))
        ->and($attempt->context['quarantine']['failing_package'] ?? null)->toBe('vendor/tampered-dependency')
        ->and($attempt->context['quarantine']['packages'] ?? null)->toBe(['vendor/tampered-dependency', $name])
        ->and($rollbacks->calls)->toBe(1)
        ->and($rollbacks->quarantinedDuringRollback)->toBe(['vendor/tampered-dependency', $name])
        ->and(ExtensionQuarantine::query()->count())->toBe(0);
});

it('keeps the whole changed set quarantined when the rollback fails', function (): void {
    Notification::fake();
    recordMarketplaceRollbacks(new RuntimeException('restore failed'));
    $name = 'vendor/verified-top-level';
    $dependency = ['name' => 'vendor/tampered-dependency', 'version' => '1.0.0', 'type' => 'composer-plugin', 'install-path' => '../vendor/tampered-dependency'];
    $attempt = marketplaceOperationAttempt(['composer_name' => $name, 'extension_slug' => 'verified-top-level']);
    VerifyInstalledExtensionAction::shouldRun()->andReturn(new ExtensionVerificationReportData($name, '1.0.0', null, null, true, [], [], CarbonImmutable::now()));

    withMarketplaceVerificationTree([], [], function (string $root) use ($attempt, $dependency): void {
        File::ensureDirectoryExists($root . '/vendor/vendor/tampered-dependency');
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(marketplaceFixtureComposerRunner($root, [$dependency], [$dependency]));
    });

    $attempt->refresh();
    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->context['rollback']['rolled_back'] ?? null)->toBeFalse()
        ->and($attempt->failure_reason)->toContain('vendor/tampered-dependency')
        ->and(ExtensionQuarantine::query()->orderBy('composer_name')->pluck('composer_name')->all())->toBe(['vendor/tampered-dependency', $name])
        ->and(ExtensionQuarantine::query()->where('composer_name', $name)->value('failing_package'))->toBe('vendor/tampered-dependency');
});

it('does not reverify an unchanged dependency', function (): void {
    Notification::fake();
    $name = 'capell-app/unchanged-dependency-target';
    $packagePath = null;
    $dependency = ['name' => 'vendor/unchanged-dependency', 'version' => '1.0.0', 'install-path' => '../vendor/unchanged-dependency', 'autoload' => ['files' => ['../outside.php']]];
    $attempt = marketplaceOperationAttempt(['composer_name' => $name, 'extension_slug' => 'unchanged-dependency-target']);
    // The requested package is a registered fixture, not a Composer install: pass its own verification so only the dependency path is real.
    VerifyInstalledExtensionAction::shouldRun()->andReturn(new ExtensionVerificationReportData($name, '1.0.0', null, null, true, [], [], CarbonImmutable::now()));

    try {
        withMarketplaceVerificationTree([$dependency], [], function (string $root) use ($attempt, $dependency, $name, &$packagePath): void {
            File::ensureDirectoryExists($root . '/vendor/vendor/unchanged-dependency');
            $runner = marketplaceFixtureComposerRunner($root, [$dependency], [$dependency], function () use ($name, &$packagePath): void {
                $packagePath = registerHealthyLifecyclePackage($name, 'Unchanged Dependency Target');
            });
            new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle($runner);
            expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
                ->and($attempt->failure_stage)->toBeNull();
        });
    } finally {
        if ($packagePath !== null) {
            File::deleteDirectory($packagePath);
        }
    }
});

it('records not_compared and fails strict verification when the release supplies no digest', function (): void {
    Notification::fake();
    config()->set('capell.extensions.verification.strict', true);
    recordMarketplaceRollbacks();
    $name = 'vendor/undigested-release';
    $attempt = marketplaceOperationAttempt(['composer_name' => $name, 'extension_slug' => 'undigested-release']);
    VerifyInstalledExtensionAction::shouldRun()->andReturn(new ExtensionVerificationReportData($name, '1.0.0', null, null, true, [], [], CarbonImmutable::now()));

    withMarketplaceVerificationTree([], [], function (string $root) use ($attempt): void {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(marketplaceFixtureComposerRunner($root, [], []));
    });

    $attempt->refresh();
    $report = $attempt->context['verification_report'] ?? [];
    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Verification->value)
        ->and($report['comparisons'] ?? null)->toBe(['not_compared'])
        ->and($report['passed'] ?? null)->toBeFalse()
        ->and(array_column($report['failures'] ?? [], 'code'))->toBe([ExtensionVerificationIssueData::RELEASE_DIGEST_NOT_COMPARED]);
});

it('records the failing content-hash comparison on the quarantine', function (): void {
    Notification::fake();
    recordMarketplaceRollbacks(new RuntimeException('restore failed'));
    $name = 'vendor/hash-mismatch';
    $expected = str_repeat('a', 64);
    $actual = str_repeat('b', 64);
    $attempt = marketplaceOperationAttempt(['composer_name' => $name, 'extension_slug' => 'hash-mismatch', 'context' => ['release_content_sha256' => $expected]]);
    $issue = new ExtensionVerificationIssueData(ExtensionVerificationIssueData::CONTENT_HASH_MISMATCH, 'mismatch', 'reinstall');
    VerifyInstalledExtensionAction::shouldRun()->andReturn(new ExtensionVerificationReportData($name, '1.0.0', null, null, false, [$issue], [], CarbonImmutable::now(), comparisons: ['content_sha256'], contentHash: $actual));

    withMarketplaceVerificationTree([], [], function (string $root) use ($attempt): void {
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle(marketplaceFixtureComposerRunner($root, [], []));
    });

    $record = ExtensionQuarantine::query()->where('composer_name', $name)->firstOrFail();
    expect([$record->failing_comparison, $record->expected_digest, $record->actual_digest])->toBe(['content_sha256', $expected, $actual]);
});

it('quarantines every package whose installed state changed when the lock cannot be parsed', function (): void {
    Notification::fake();
    $name = 'vendor/graph-target';
    $unchanged = ['name' => 'vendor/unchanged', 'version' => '1.0.0', 'install-path' => '../vendor/unchanged'];
    $added = ['name' => 'vendor/added-dependency', 'version' => '1.0.0', 'install-path' => '../vendor/added-dependency'];
    $target = ['name' => $name, 'version' => '1.0.0', 'install-path' => '../vendor/graph-target'];
    $attempt = marketplaceOperationAttempt(['composer_name' => $name, 'extension_slug' => 'graph-target']);
    VerifyInstalledExtensionAction::shouldRun()->andReturn(new ExtensionVerificationReportData($name, '1.0.0', null, null, true, [], [], CarbonImmutable::now(), comparisons: ['content_sha256']));
    // The rollback cannot prove the restored bytes, so the quarantine must stay.
    $rollbacks = new class
    {
        /** @var list<array<string, string|null>> */
        public array $expectedHashes = [];

        public function handle(ComposerStateSnapshot $snapshot, int $timeoutSeconds = ComposerStateSnapshot::DEFAULT_TIMEOUT_SECONDS, array $expectedContentHashes = []): bool
        {
            $this->expectedHashes[] = $expectedContentHashes;

            return false;
        }
    };
    app()->instance(RestoreComposerStateAction::class, $rollbacks);

    withMarketplaceVerificationTree([], [$unchanged], function (string $root) use ($attempt, $unchanged, $added, $target): void {
        $runner = marketplaceFixtureComposerRunner($root, [], [$unchanged, $added, $target], function () use ($root): void {
            File::put($root . '/composer.lock', '{not json');
        });
        new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle($runner);
    });

    $attempt->refresh();
    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_reason)->toContain(__('capell-marketplace::marketplace.operations.graph_discovery_failed'))
        ->and($attempt->failure_reason)->toContain(__('capell-marketplace::marketplace.operations.rollback_bytes_unverified'))
        ->and($attempt->context['rollback']['rolled_back'] ?? null)->toBeFalse()
        ->and($rollbacks->expectedHashes)->toBe([['vendor/added-dependency' => null, $name => null]])
        ->and(ExtensionQuarantine::query()->orderBy('composer_name')->pluck('composer_name')->all())->toBe(['vendor/added-dependency', $name]);
});
