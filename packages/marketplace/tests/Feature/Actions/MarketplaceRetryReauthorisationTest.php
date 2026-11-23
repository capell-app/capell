<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\RetryMarketplaceInstallAttemptAction;
use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Jobs\RunMarketplaceUninstallAttemptJob;
use Capell\Marketplace\Jobs\RunMarketplaceUpdateAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstance;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

const REAUTH_PACKAGE = 'capell-app/reauth-suite';

/** @param array<string, mixed> $authorisation */
function fakeReauthMarketplace(array|int $authorisation, bool $installed = true): void
{
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-reauth',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_reauth',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);
    config()->set('capell-marketplace.marketplace.base_url', 'https://marketplace.test/api');

    $response = is_int($authorisation) ? Http::response(['message' => 'Licence revoked.'], $authorisation) : Http::response(['data' => $authorisation]);

    Http::fake([
        'marketplace.test/api/extensions/by-composer*' => Http::response(['data' => [[
            'slug' => 'reauth-suite',
            'name' => 'Reauth Suite',
            'composer_name' => REAUTH_PACKAGE,
            'kind' => 'plugin',
            'price_cents' => 4900,
            'is_paid' => true,
            'latest_version' => '2.4.0',
            'install_eligibility' => ['state' => 'authorized', 'can_install' => true, 'can_update' => true, 'can_run_existing' => true],
        ]]]),
        'marketplace.test/api/extensions/upgrade-authorization' => $response,
        'marketplace.test/api/extensions/reauth-suite/install-authorization' => $response,
    ]);

    if ($installed) {
        CapellCore::registerPackage(REAUTH_PACKAGE, version: '2.1.0');
        CapellCore::forcePackageInstalled(REAUTH_PACKAGE);
        app()->instance(MarketplaceInstalledPackageVersionResolver::class, new readonly class implements MarketplaceInstalledPackageVersionResolver
        {
            #[Override]
            public function prettyVersion(string $composerName): ?string
            {
                return $composerName === REAUTH_PACKAGE ? '2.1.0' : null;
            }
        });
    }
}

/** @param array<string, mixed> $overrides */
function failedReauthAttempt(MarketplaceOperationType $operation, array $overrides = []): MarketplaceInstallAttempt
{
    return MarketplaceInstallAttempt::query()->create([
        'composer_name' => REAUTH_PACKAGE,
        'extension_slug' => 'reauth-suite',
        'extension_name' => 'Reauth Suite',
        'kind' => 'plugin',
        'status' => MarketplaceInstallIntentStatus::Failed,
        'operation' => $operation,
        'version_constraint' => '^2.3.0',
        'failure_reason' => 'Composer failed.',
        'context' => [
            'update_from_version' => '2.1.0',
            'composer_auth_encrypted' => Crypt::encryptString(json_encode(['http-basic' => ['packages.test' => ['username' => 'stale-token']]], JSON_THROW_ON_ERROR)),
        ],
        'queued_at' => now()->subDays(3),
        'completed_at' => now()->subDays(3),
        ...$overrides,
    ]);
}

/** @return array<string, mixed> */
function storedComposerAuth(MarketplaceInstallAttempt $attempt): array
{
    $encrypted = $attempt->context['composer_auth_encrypted'] ?? null;

    return is_string($encrypted) ? json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR) : [];
}

function freshAuthorisation(array $overrides = []): array
{
    return [
        'composer_name' => REAUTH_PACKAGE,
        'version_constraint' => '^2.4.0',
        'composer_auth' => ['http-basic' => ['packages.test' => ['username' => 'fresh-token']]],
        ...$overrides,
    ];
}

it('asks the marketplace to authorise an update again and runs on the fresh credentials, not the stored ones', function (): void {
    Queue::fake();
    fakeReauthMarketplace(freshAuthorisation());
    $source = failedReauthAttempt(MarketplaceOperationType::Update);

    $retry = RetryMarketplaceInstallAttemptAction::run($source);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/extensions/upgrade-authorization')
        && $request['composer_name'] === REAUTH_PACKAGE
        && $request['current_version'] === '2.1.0');
    expect($retry->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($retry->version_constraint)->toBe('^2.4.0')
        ->and(storedComposerAuth($retry))->toBe(['http-basic' => ['packages.test' => ['username' => 'fresh-token']]])
        // The failed attempt is history and keeps what it had.
        ->and(storedComposerAuth($source->refresh())['http-basic']['packages.test']['username'])->toBe('stale-token');
    Queue::assertPushed(RunMarketplaceUpdateAttemptJob::class, 1);
});

it('refuses a retry when the marketplace now denies the authorisation, without queueing or reusing credentials', function (): void {
    Queue::fake();
    fakeReauthMarketplace(403);
    $source = failedReauthAttempt(MarketplaceOperationType::Update);

    $retry = RetryMarketplaceInstallAttemptAction::run($source);

    expect($retry->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($retry->failure_stage)->toBe(MarketplaceInstallFailureStage::Preflight->value)
        ->and($retry->failure_reason)->toStartWith(Str::before((string) __('capell-marketplace::marketplace.operations.retry_reauthorisation_failed', ['reason' => 'x']), 'x'))
        ->and($retry->context['composer_auth_encrypted'] ?? null)->toBeNull();
    Queue::assertNothingPushed();
});

it('refuses a retry when the fresh authorisation has already expired', function (): void {
    Queue::fake();
    fakeReauthMarketplace(freshAuthorisation(['expires_at' => now()->subMinute()->toIso8601String()]));
    $source = failedReauthAttempt(MarketplaceOperationType::Update);

    $retry = RetryMarketplaceInstallAttemptAction::run($source);

    expect($retry->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($retry->failure_reason)->toBe((string) __('capell-marketplace::marketplace.operations.retry_reauthorisation_expired'))
        ->and($retry->context['composer_auth_encrypted'] ?? null)->toBeNull();
    Queue::assertNothingPushed();
});

it('keeps an unattended update pinned when it is retried', function (): void {
    Queue::fake();
    fakeReauthMarketplace(freshAuthorisation(['version_constraint' => '^2.4.0']));
    $source = failedReauthAttempt(MarketplaceOperationType::Update, [
        'context' => ['update_from_version' => '2.1.0', 'install_source' => MarketplaceInstallSource::Scheduler->value],
    ]);

    $retry = RetryMarketplaceInstallAttemptAction::run($source);

    expect($retry->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($retry->failure_reason)->toContain((string) __('capell-marketplace::marketplace.errors.unattended_update_not_pinned'));
    Queue::assertNothingPushed();
});

it('asks the marketplace to authorise an install again and replaces the stored credentials', function (): void {
    Queue::fake();
    fakeReauthMarketplace(freshAuthorisation(), installed: false);
    $source = failedReauthAttempt(MarketplaceOperationType::Install);

    $retry = RetryMarketplaceInstallAttemptAction::run($source);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/extensions/reauth-suite/install-authorization'));
    expect($retry->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and(storedComposerAuth($retry)['http-basic']['packages.test']['username'])->toBe('fresh-token');
    Queue::assertPushed(RunMarketplaceInstallAttemptJob::class, 1);
});

it('refuses an install retry once the licence has been withdrawn', function (): void {
    Queue::fake();
    fakeReauthMarketplace(403, installed: false);
    $source = failedReauthAttempt(MarketplaceOperationType::Install);

    $retry = RetryMarketplaceInstallAttemptAction::run($source);

    expect($retry->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($retry->context['composer_auth_encrypted'] ?? null)->toBeNull();
    Queue::assertNothingPushed();
});

it('retries an uninstall without contacting the marketplace and without carrying registry credentials', function (): void {
    Queue::fake();
    Http::fake();
    CapellCore::registerPackage(REAUTH_PACKAGE, version: '2.1.0');
    CapellCore::forcePackageInstalled(REAUTH_PACKAGE);
    $source = failedReauthAttempt(MarketplaceOperationType::Uninstall);

    $retry = RetryMarketplaceInstallAttemptAction::run($source);

    expect($retry->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($retry->context['composer_auth_encrypted'] ?? null)->toBeNull();
    Http::assertNothingSent();
    Queue::assertPushed(RunMarketplaceUninstallAttemptJob::class, 1);
});
