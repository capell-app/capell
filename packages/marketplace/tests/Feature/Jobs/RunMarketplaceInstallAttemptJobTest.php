<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Tests\Support\Fixtures\Autoload\LifecycleRecorderAction;
use Capell\Marketplace\Actions\CancelMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\NotifyMarketplaceInstallCompletedAction;
use Capell\Marketplace\Contracts\MarketplaceAuthenticatedComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceInstallNotifications;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Notifications\BroadcastNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
        ->and($job->tries)->toBe(30)
        ->and($job->backoff())->toBe([30, 60, 120, 300]);
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
        ->and($attempt->resolved_at)->toBeNull();

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
