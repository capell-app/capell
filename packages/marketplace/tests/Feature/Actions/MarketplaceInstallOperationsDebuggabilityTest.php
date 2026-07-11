<?php

declare(strict_types=1);

use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Marketplace\Actions\ApplyMarketplaceThemeToSitesAction;
use Capell\Marketplace\Actions\BuildMarketplaceInstallDiagnosticBundleAction;
use Capell\Marketplace\Actions\ClassifyMarketplaceInstallFailureAction;
use Capell\Marketplace\Actions\FindActiveMarketplaceInstallOperationAction;
use Capell\Marketplace\Actions\ListMarketplaceInstallOperationsAction;
use Capell\Marketplace\Actions\NotifyMarketplaceInstallOperationFailureAction;
use Capell\Marketplace\Actions\RecordMarketplaceInstallAttemptEventAction;
use Capell\Marketplace\Actions\RedactMarketplaceDiagnosticContextAction;
use Capell\Marketplace\Actions\RetryMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\RunMarketplaceInstallPreflightChecksAction;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Notifications\MarketplaceInstallOperationFailedNotification;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Notifications\BroadcastNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(CreatesAdminUser::class);

it('passes preflight for a sane local install attempt', function (): void {
    $attempt = marketplaceDebugAttempt();

    $result = RunMarketplaceInstallPreflightChecksAction::run($attempt);

    expect($result['passed'])->toBeTrue()
        ->and($attempt->events()->count())->toBeGreaterThan(0);
});

it('fails preflight for duplicate active installs before dispatching composer', function (): void {
    $source = marketplaceDebugAttempt([
        'status' => MarketplaceInstallIntentStatus::Running,
    ]);
    $attempt = marketplaceDebugAttempt([
        'composer_name' => $source->composer_name,
        'extension_slug' => 'seo-suite-retry',
    ]);

    $result = RunMarketplaceInstallPreflightChecksAction::run($attempt);

    expect($result['passed'])->toBeFalse()
        ->and(collect($result['checks'])->where('name', 'no_duplicate_active_install')->first()['passed'])->toBeFalse();
});

it('allows installed packages when retrying cancel-after-composer recovery', function (): void {
    CapellCore::forcePackageInstalled('capell-app/recovered-suite');

    $source = marketplaceDebugAttempt([
        'composer_name' => 'capell-app/recovered-suite',
        'extension_slug' => 'recovered-suite',
        'status' => MarketplaceInstallIntentStatus::Cancelled,
        'failure_type' => MarketplaceInstallFailureType::CancelledAfterComposer->value,
    ]);
    $retry = marketplaceDebugAttempt([
        'composer_name' => 'capell-app/recovered-suite',
        'extension_slug' => 'recovered-suite-retry',
        'retry_of_id' => $source->getKey(),
    ]);

    $result = RunMarketplaceInstallPreflightChecksAction::run($retry);

    expect($result['passed'])->toBeTrue()
        ->and(collect($result['checks'])->where('name', 'package_not_installed')->first()['passed'])->toBeTrue();
});

it('allows downloaded packages that have not run Capell extension lifecycle yet', function (): void {
    CapellCore::registerPackage('capell-app/downloaded-suite', version: '1.0.0');
    CapellCore::forcePackageInstalled('capell-app/downloaded-suite', false);

    $attempt = marketplaceDebugAttempt([
        'composer_name' => 'capell-app/downloaded-suite',
        'extension_slug' => 'downloaded-suite',
        'extension_name' => 'Downloaded Suite',
    ]);

    $result = RunMarketplaceInstallPreflightChecksAction::run($attempt);

    expect($result['passed'])->toBeTrue()
        ->and(collect($result['checks'])->where('name', 'package_not_installed')->first()['passed'])->toBeTrue();
});

it('classifies known composer and runtime failures', function (MarketplaceComposerResultData|string $input, MarketplaceInstallFailureType $expectedType): void {
    $classification = $input instanceof MarketplaceComposerResultData
        ? ClassifyMarketplaceInstallFailureAction::run(stage: MarketplaceInstallFailureStage::Composer, composerResult: $input)
        : ClassifyMarketplaceInstallFailureAction::run(stage: MarketplaceInstallFailureStage::Composer, message: $input);

    expect($classification['failure_type'])->toBe($expectedType);
})->with([
    'php binary' => ['PHP CLI binary could not be found.', MarketplaceInstallFailureType::PhpBinary],
    'timeout' => [new MarketplaceComposerResultData(124, '', 'Timed out', true), MarketplaceInstallFailureType::Timeout],
    'constraint' => ['Your requirements could not be resolved to an installable set of packages.', MarketplaceInstallFailureType::ComposerConstraint],
    'auth' => ['HTTP 401 authentication required for private repository.', MarketplaceInstallFailureType::ComposerAuth],
    'network' => ['curl error could not resolve host.', MarketplaceInstallFailureType::Network],
    'deployment unavailable' => ['Deployments is unavailable for this Composer change.', MarketplaceInstallFailureType::DeploymentUnavailable],
]);

it('infers marketplace install failure stage and type from operation diagnostics', function (
    string $message,
    MarketplaceInstallFailureStage $expectedStage,
    MarketplaceInstallFailureType $expectedType,
): void {
    $classification = ClassifyMarketplaceInstallFailureAction::run(message: $message);

    expect($classification['failure_stage'])->toBe($expectedStage)
        ->and($classification['failure_type'])->toBe($expectedType);
})->with([
    'package discovery' => [
        'Package was not discovered in the registry after composer completed.',
        MarketplaceInstallFailureStage::PackageDiscovery,
        MarketplaceInstallFailureType::PackageNotDiscovered,
    ],
    'cancelled after composer' => [
        'Install cancelled after composer while waiting for operator confirmation.',
        MarketplaceInstallFailureStage::Composer,
        MarketplaceInstallFailureType::CancelledAfterComposer,
    ],
    'deployment failed' => [
        'Deployment failed after marketplace package installation.',
        MarketplaceInstallFailureStage::DeploymentHandoff,
        MarketplaceInstallFailureType::DeploymentFailed,
    ],
    'lifecycle exception' => [
        'Lifecycle hook threw an exception during extension boot.',
        MarketplaceInstallFailureStage::Lifecycle,
        MarketplaceInstallFailureType::LifecycleException,
    ],
    'queue exhaustion' => [
        'Queue job attempted too many times.',
        MarketplaceInstallFailureStage::Queue,
        MarketplaceInstallFailureType::Unknown,
    ],
]);

it('retries by creating a linked attempt without mutating the failed source attempt', function (): void {
    Notification::fake();
    Queue::fake();

    $failedAttempt = marketplaceDebugAttempt([
        'status' => MarketplaceInstallIntentStatus::Failed,
        'failure_reason' => 'Composer failed.',
        'completed_at' => now()->subMinute(),
    ]);

    $retry = RetryMarketplaceInstallAttemptAction::run($failedAttempt);

    expect($retry->getKey())->not->toBe($failedAttempt->getKey())
        ->and($retry->retry_of_id)->toBe($failedAttempt->getKey())
        ->and($retry->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($retry->events()->count())->toBeGreaterThan(0)
        ->and($failedAttempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Failed);
});

it('marks retry attempts failed when preflight still blocks the package operation', function (): void {
    Notification::fake();
    Queue::fake();
    CapellCore::forcePackageInstalled('capell-app/already-installed-suite');

    $failedAttempt = marketplaceDebugAttempt([
        'composer_name' => 'capell-app/already-installed-suite',
        'extension_slug' => 'already-installed-suite',
        'extension_name' => 'Already Installed Suite',
        'status' => MarketplaceInstallIntentStatus::Failed,
        'failure_reason' => 'Composer failed before recovery.',
        'completed_at' => now()->subMinute(),
    ]);

    $retry = RetryMarketplaceInstallAttemptAction::run($failedAttempt);

    expect($retry->getKey())->not->toBe($failedAttempt->getKey())
        ->and($retry->retry_of_id)->toBe($failedAttempt->getKey())
        ->and($retry->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($retry->failure_stage)->toBe(MarketplaceInstallFailureStage::Preflight->value)
        ->and($retry->failure_reason)->toContain('package not installed')
        ->and($retry->events()->where('stage', MarketplaceInstallFailureStage::Preflight->value)->exists())->toBeTrue();
});

it('builds a redacted diagnostic bundle with status, timeline, and output', function (): void {
    $attempt = marketplaceDebugAttempt([
        'status' => MarketplaceInstallIntentStatus::Failed,
        'failure_reason' => 'Composer auth failed.',
        'failure_type' => MarketplaceInstallFailureType::ComposerAuth->value,
        'failure_stage' => MarketplaceInstallFailureStage::Composer->value,
        'context' => ['token' => 'secret-token'],
        'diagnostic_context' => ['MARKETPLACE_SIGNING_SECRET' => 'secret'],
        'error_excerpt' => 'password=hunter2 {"token":"json-secret"} Bearer abc+/=',
    ]);

    RecordMarketplaceInstallAttemptEventAction::run(
        attempt: $attempt,
        level: MarketplaceInstallAttemptEventLevel::Error,
        message: 'Composer failed',
        stage: MarketplaceInstallFailureStage::Composer,
        context: ['auth_json' => '{"token":"secret"}'],
    );

    $bundle = BuildMarketplaceInstallDiagnosticBundleAction::run($attempt);

    expect($bundle)->toContain('"composer_name": "capell-app/seo-suite"')
        ->and($bundle)->toContain('"failure_type": "composer_auth"')
        ->and($bundle)->toContain('"timeline"')
        ->and($bundle)->not->toContain('secret-token')
        ->and($bundle)->not->toContain('hunter2')
        ->and($bundle)->not->toContain('json-secret')
        ->and($bundle)->not->toContain('abc+/=')
        ->and($bundle)->not->toContain('{"token":"secret"}');
});

it('redacts composer auth credentials embedded in diagnostic strings', function (): void {
    $redacted = RedactMarketplaceDiagnosticContextAction::run([
        'output' => 'Bearer abc+/= password=hunter2 {"github-oauth":{"github.com":"ghp_secret_token"},"http-basic":{"repo.example.com":"basic_secret"}}',
    ]);

    expect($redacted['output'])
        ->toContain('Bearer [redacted]')
        ->toContain('password=[redacted]')
        ->not->toContain('abc+/=')
        ->not->toContain('hunter2')
        ->not->toContain('ghp_secret_token')
        ->not->toContain('basic_secret');
});

it('notifies subscribed admins when a package operation failure is escalated', function (): void {
    Notification::fake();
    $admin = test()->createUserWithRole('super_admin');
    $attempt = marketplaceDebugAttempt([
        'status' => MarketplaceInstallIntentStatus::Failed,
        'failure_reason' => 'Composer could not resolve the package.',
        'completed_at' => now(),
    ]);

    NotifyMarketplaceInstallOperationFailureAction::run($attempt);

    Notification::assertSentTo(
        $admin,
        MarketplaceInstallOperationFailedNotification::class,
        fn (MarketplaceInstallOperationFailedNotification $notification): bool => $notification
            ->toMail($admin)
            ->subject === (string) __('capell-marketplace::marketplace.operations.notification_subject', [
                'package' => 'capell-app/seo-suite',
            ]),
    );
});

it('broadcasts package operation failures to the requesting user even without subscribed recipients', function (): void {
    Notification::fake();
    $requestingUser = test()->createUser();
    $attempt = marketplaceDebugAttempt([
        'status' => MarketplaceInstallIntentStatus::Failed,
        'user_id' => (string) $requestingUser->getKey(),
        'failure_reason' => null,
        'completed_at' => now(),
    ]);

    NotifyMarketplaceInstallOperationFailureAction::run($attempt);

    Notification::assertSentTo(
        $requestingUser,
        BroadcastNotification::class,
        fn (BroadcastNotification $notification): bool => $notification->data['body'] === (string) __('capell-marketplace::marketplace.operations.notification_unknown_reason'),
    );
    Notification::assertNotSentTo($requestingUser, MarketplaceInstallOperationFailedNotification::class);
});

it('lists unresolved install operations by attention and active status', function (): void {
    marketplaceDebugAttempt([
        'composer_name' => 'capell-app/queued-suite',
        'extension_slug' => 'queued-suite',
        'status' => MarketplaceInstallIntentStatus::Queued,
    ]);
    marketplaceDebugAttempt([
        'composer_name' => 'capell-app/running-suite',
        'extension_slug' => 'running-suite',
        'status' => MarketplaceInstallIntentStatus::Running,
    ]);
    marketplaceDebugAttempt([
        'composer_name' => 'capell-app/failed-suite',
        'extension_slug' => 'failed-suite',
        'status' => MarketplaceInstallIntentStatus::Failed,
        'completed_at' => now(),
    ]);
    marketplaceDebugAttempt([
        'composer_name' => 'capell-app/resolved-suite',
        'extension_slug' => 'resolved-suite',
        'status' => MarketplaceInstallIntentStatus::Failed,
        'completed_at' => now(),
        'resolved_at' => now(),
    ]);
    marketplaceDebugAttempt([
        'composer_name' => 'capell-app/deployment-suite',
        'extension_slug' => 'deployment-suite',
        'status' => MarketplaceInstallIntentStatus::Succeeded,
        'deployment' => ['status' => 'unavailable'],
    ]);

    $action = new ListMarketplaceInstallOperationsAction;

    expect($action->handle(attentionOnly: true, limit: null)->pluck('composer_name')->sort()->values()->all())
        ->toBe([
            'capell-app/deployment-suite',
            'capell-app/failed-suite',
        ])
        ->and($action->legacyCount())->toBe(4)
        ->and($action->legacyCount(attentionOnly: true))->toBe(2)
        ->and($action->activeCount())->toBe(2)
        ->and($action->legacyActiveCount())->toBe(2);
});

it('finds the latest active package operation while ignoring completed attempts', function (): void {
    marketplaceDebugAttempt([
        'composer_name' => 'capell-app/active-suite',
        'extension_slug' => 'active-suite-failed',
        'status' => MarketplaceInstallIntentStatus::Failed,
        'completed_at' => now(),
    ]);
    $queuedAttempt = marketplaceDebugAttempt([
        'composer_name' => 'capell-app/active-suite',
        'extension_slug' => 'active-suite-queued',
        'status' => MarketplaceInstallIntentStatus::Queued,
    ]);
    $runningAttempt = marketplaceDebugAttempt([
        'composer_name' => 'capell-app/active-suite',
        'extension_slug' => 'active-suite-running',
        'status' => MarketplaceInstallIntentStatus::Running,
    ]);
    $queuedAttempt->forceFill(['created_at' => now()->subMinute()])->save();
    $runningAttempt->forceFill(['created_at' => now()])->save();

    expect(FindActiveMarketplaceInstallOperationAction::run('capell-app/active-suite')->is($runningAttempt))->toBeTrue()
        ->and(FindActiveMarketplaceInstallOperationAction::run('capell-app/missing-suite'))->toBeNull()
        ->and($queuedAttempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Queued);
});

it('applies marketplace themes to every site and promotes the theme as default', function (): void {
    Event::fake([FrontendSurrogateKeysInvalidated::class]);

    $previousTheme = Theme::factory()->create([
        'default' => true,
        'status' => true,
    ]);
    $firstSite = Site::factory()->theme($previousTheme)->create();
    $secondSite = Site::factory()->theme($previousTheme)->create();

    $theme = ApplyMarketplaceThemeToSitesAction::run(
        themeKey: 'marketplace-theme',
        themeName: 'Marketplace Theme',
    );

    expect($theme->key)->toBe('marketplace-theme')
        ->and($theme->name)->toBe('Marketplace Theme')
        ->and($theme->status)->toBeTrue()
        ->and($theme->default)->toBeTrue()
        ->and($previousTheme->refresh()->default)->toBeFalse()
        ->and($firstSite->refresh()->theme_id)->toBe($theme->getKey())
        ->and($secondSite->refresh()->theme_id)->toBe($theme->getKey());

    Event::assertDispatched(
        FrontendSurrogateKeysInvalidated::class,
        fn (FrontendSurrogateKeysInvalidated $event): bool => $event->surrogateKeys === [
            'site-' . $firstSite->getKey(),
            'site-' . $secondSite->getKey(),
        ],
    );
});

it('applies marketplace themes to one selected site without changing the default theme', function (): void {
    Event::fake([FrontendSurrogateKeysInvalidated::class]);

    $previousTheme = Theme::factory()->create([
        'default' => true,
        'status' => true,
    ]);
    $firstSite = Site::factory()->theme($previousTheme)->create();
    $secondSite = Site::factory()->theme($previousTheme)->create();

    $theme = ApplyMarketplaceThemeToSitesAction::run(
        themeKey: 'site-theme',
        themeName: 'Site Theme',
        siteId: (int) $firstSite->getKey(),
    );

    expect($theme->key)->toBe('site-theme')
        ->and($theme->status)->toBeTrue()
        ->and($theme->default)->toBeFalse()
        ->and($previousTheme->refresh()->default)->toBeTrue()
        ->and($firstSite->refresh()->theme_id)->toBe($theme->getKey())
        ->and($secondSite->refresh()->theme_id)->toBe($previousTheme->getKey());

    Event::assertDispatched(
        FrontendSurrogateKeysInvalidated::class,
        fn (FrontendSurrogateKeysInvalidated $event): bool => $event->surrogateKeys === [
            'site-' . $firstSite->getKey(),
        ],
    );
});

function marketplaceDebugAttempt(array $overrides = []): MarketplaceInstallAttempt
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
