<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Actions\RecordMarketplaceInstallAttemptEventAction;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Filament\Pages\MarketplacePackageOperationsPage;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    grantPackageOperationsPageAccess();
});

function grantPackageOperationsPageAccess(): void
{
    Permission::findOrCreate('View:ExtensionsPage', 'web');
    Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web');

    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:ExtensionsPage', ExtensionsPage::MANAGE_PERMISSION);
}

function grantPackageOperationsPageViewOnly(): void
{
    Permission::findOrCreate('View:ExtensionsPage', 'web');
    Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web');

    test()->actingAsUser();
    test()->authenticatedUser()->givePermissionTo('View:ExtensionsPage');
}

it('renders filterable package operations with selected operation details and timeline', function (): void {
    $attempt = marketplacePackageOperationsPageAttempt([
        'status' => MarketplaceInstallIntentStatus::Failed,
        'failure_reason' => 'Composer failed.',
        'failure_type' => MarketplaceInstallFailureType::ComposerConstraint->value,
        'failure_stage' => MarketplaceInstallFailureStage::Composer->value,
        'completed_at' => now(),
    ]);

    RecordMarketplaceInstallAttemptEventAction::run(
        attempt: $attempt,
        level: MarketplaceInstallAttemptEventLevel::Error,
        message: 'Composer failed',
        stage: MarketplaceInstallFailureStage::Composer,
    );

    Livewire::test(MarketplacePackageOperationsPage::class)
        ->set('activeTab', 'failed')
        ->set('selectedOperationId', $attempt->getKey())
        ->assertSee(__('capell-marketplace::marketplace.operations.page_title'))
        ->assertSee('SEO Suite')
        ->assertSee('composer_constraint')
        ->assertSee('Composer failed');
});

it('uses extension breadcrumbs and header actions with filament table search for package operations', function (): void {
    marketplacePackageOperationsPageAttempt([
        'composer_name' => 'capell-app/seo-suite',
        'extension_name' => 'SEO Suite',
        'status' => MarketplaceInstallIntentStatus::Failed,
        'completed_at' => now(),
    ]);

    marketplacePackageOperationsPageAttempt([
        'composer_name' => 'capell-app/forms',
        'extension_name' => 'Forms',
        'status' => MarketplaceInstallIntentStatus::Failed,
        'completed_at' => now(),
    ]);

    $page = resolve(MarketplacePackageOperationsPage::class);
    $headerActions = collect((fn (): array => $this->getHeaderActions())->call($page));

    expect($page->getBreadcrumbs())->toBe([
        ExtensionsPage::getUrl() => ExtensionsPage::getNavigationLabel(),
        MarketplacePage::getUrl() => MarketplacePage::getNavigationLabel(),
        MarketplacePackageOperationsPage::getNavigationLabel(),
    ])
        ->and($headerActions)
        ->toHaveCount(2)
        ->each->toBeInstanceOf(Action::class)
        ->and($headerActions->map(fn (Action $action): string => filamentText($action->getLabel()))->all())->toBe([
            ExtensionsPage::getNavigationLabel(),
            MarketplacePage::getNavigationLabel(),
        ]);

    Livewire::test(MarketplacePackageOperationsPage::class)
        ->set('activeTab', 'failed')
        ->set('tableSearch', 'seo-suite')
        ->assertSee('SEO Suite')
        ->assertDontSee('Forms');
});

it('exposes redacted diagnostics and can mark operations resolved', function (): void {
    $attempt = marketplacePackageOperationsPageAttempt([
        'status' => MarketplaceInstallIntentStatus::Failed,
        'failure_reason' => 'Composer auth failed.',
        'failure_type' => MarketplaceInstallFailureType::ComposerAuth->value,
        'failure_stage' => MarketplaceInstallFailureStage::Composer->value,
        'diagnostic_context' => ['license_key' => 'lic_secret'],
        'completed_at' => now(),
    ]);

    Livewire::test(MarketplacePackageOperationsPage::class)
        ->set('activeTab', 'failed')
        ->set('selectedOperationId', $attempt->getKey())
        ->call('copyDiagnostics', $attempt->getKey())
        ->assertSet('diagnosticBundle', fn (?string $bundle): bool => is_string($bundle)
            && str_contains($bundle, 'composer_auth')
            && ! str_contains($bundle, 'lic_secret'))
        ->call('markResolved', $attempt->getKey())
        ->assertSet('activeTab', 'resolved');

    expect($attempt->refresh()->resolved_at)->not->toBeNull();
});

it('shows auto-resolved successful attempts on the succeeded tab', function (): void {
    marketplacePackageOperationsPageAttempt([
        'status' => MarketplaceInstallIntentStatus::Succeeded,
        'completed_at' => now(),
        'resolved_at' => now(),
    ]);

    Livewire::test(MarketplacePackageOperationsPage::class)
        ->set('activeTab', 'succeeded')
        ->assertSee('SEO Suite')
        ->assertSee('capell-app/seo-suite');
});

it('does not mark active operations resolved through direct livewire calls', function (): void {
    $attempt = marketplacePackageOperationsPageAttempt([
        'status' => MarketplaceInstallIntentStatus::Running,
        'started_at' => now(),
    ]);

    Livewire::test(MarketplacePackageOperationsPage::class)
        ->call('markResolved', $attempt->getKey());

    expect($attempt->refresh()->resolved_at)->toBeNull();
});

it('does not mutate package operations for extension viewers without manage permission', function (): void {
    grantPackageOperationsPageViewOnly();

    $failedAttempt = marketplacePackageOperationsPageAttempt([
        'status' => MarketplaceInstallIntentStatus::Failed,
        'failure_reason' => 'Composer failed.',
        'failure_type' => MarketplaceInstallFailureType::ComposerConstraint->value,
        'failure_stage' => MarketplaceInstallFailureStage::Composer->value,
        'completed_at' => now(),
        'resolved_at' => null,
    ]);
    $queuedAttempt = marketplacePackageOperationsPageAttempt([
        'composer_name' => 'capell-app/queued-suite',
        'extension_slug' => 'queued-suite',
        'status' => MarketplaceInstallIntentStatus::Queued,
    ]);

    Livewire::test(MarketplacePackageOperationsPage::class)
        ->set('activeTab', 'failed')
        ->call('copyDiagnostics', $failedAttempt->getKey())
        ->assertSet('diagnosticBundle', null)
        ->call('retry', $failedAttempt->getKey())
        ->call('markResolved', $failedAttempt->getKey())
        ->call('cancel', $queuedAttempt->getKey());

    expect($failedAttempt->refresh()->resolved_at)->toBeNull()
        ->and(MarketplaceInstallAttempt::query()->where('retry_of_id', $failedAttempt->getKey())->exists())->toBeFalse()
        ->and($queuedAttempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Queued);
});

function marketplacePackageOperationsPageAttempt(array $overrides = []): MarketplaceInstallAttempt
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
