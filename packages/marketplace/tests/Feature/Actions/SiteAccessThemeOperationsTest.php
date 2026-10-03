<?php

declare(strict_types=1);

use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Marketplace\Actions\ApplyMarketplaceThemeToSitesAction;
use Capell\Marketplace\Actions\ApplyRequestedThemeActivationAction;
use Capell\Marketplace\Actions\AssertMarketplaceUninstallAllowedAction;
use Capell\Marketplace\Actions\RecordThemeInstallIntentAction;
use Capell\Marketplace\Actions\ResolveMarketplaceInstallAttemptUserAction;
use Capell\Marketplace\Data\MarketplaceUninstallOptionsData;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Auth\Access\AuthorizationException;

uses(CreatesAdminUser::class);

function siteAccessThemeAttempt(): MarketplaceInstallAttempt
{
    RecordThemeInstallIntentAction::run(
        'worker-theme',
        'Worker Theme',
        'capell-app/worker-theme',
        'composer require capell-app/worker-theme',
        '^1',
        null,
        null,
        [RecordThemeInstallIntentAction::ACTIVATE_AFTER_INSTALL => true],
    );

    return MarketplaceInstallAttempt::query()->create(['composer_name' => 'capell-app/worker-theme', 'extension_slug' => 'worker-theme', 'extension_name' => 'Worker Theme', 'kind' => 'theme', 'status' => 'queued']);
}

it('uses the queued initiating actors access without an authenticated request', function (): void {
    $assigned = Site::factory()->create();
    $foreign = Site::factory()->create();
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $assigned->getKey()]);
    bindFakeAction(ResolveMarketplaceInstallAttemptUserAction::class, $actor);
    $attempt = siteAccessThemeAttempt();
    $theme = ApplyRequestedThemeActivationAction::run($attempt);

    expect($assigned->refresh()->theme_id)->toBe($theme?->getKey())
        ->and($foreign->refresh()->theme_id)->not->toBe($theme?->getKey());
});

it('denies queued activation with no initiating actor even in another users request', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->create();
    $original = $site->theme_id;
    bindFakeAction(ResolveMarketplaceInstallAttemptUserAction::class);
    $attempt = siteAccessThemeAttempt();

    expect(fn (): mixed => ApplyRequestedThemeActivationAction::run($attempt))->toThrow(AuthorizationException::class);
    expect($site->refresh()->theme_id)->toBe($original);
});

it('preserves global all site theme application when no sites exist', function (): void {
    test()->actingAsAdmin();
    $theme = ApplyMarketplaceThemeToSitesAction::run('empty-installation', 'Empty Installation');

    expect($theme->default)->toBeTrue()->and($theme->status)->toBeTrue();
});

it('does not promote the first scoped theme to the installation default', function (): void {
    $site = Site::factory()->create();
    Theme::query()->update(['default' => false]);
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $site->getKey()]);

    test()->actingAs($actor);
    $theme = ApplyMarketplaceThemeToSitesAction::run('scoped-first', 'Scoped First');

    expect($theme->default)->toBeFalse()->and($site->refresh()->theme_id)->toBe($theme->getKey());
});

it('retains global uninstall protection without exposing foreign usage counts', function (): void {
    CapellCore::registerPackage('capell-app/guarded-theme', PackageTypeEnum::Theme, version: '1.0.0');
    $package = CapellCore::getPackage('capell-app/guarded-theme');
    $package->themeKey = 'guarded-theme';
    CapellCore::markPackageInstalled($package->name);
    $theme = Theme::factory()->create(['key' => 'guarded-theme']);
    Site::factory()->theme($theme)->count(2)->create();
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect();

    test()->actingAs($actor);

    $reason = resolve(AssertMarketplaceUninstallAllowedAction::class)->refusalReason($package->name, new MarketplaceUninstallOptionsData);
    expect($reason)->toBeString()->not->toContain('2 site(s)')->not->toContain('global active theme:');
});

it('preserves an existing installation default when a scoped actor reapplies that theme', function (): void {
    $theme = Theme::factory()->create(['key' => 'existing-default', 'default' => true]);
    $site = Site::factory()->theme($theme)->create();
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $site->getKey()]);

    test()->actingAs($actor);

    ApplyMarketplaceThemeToSitesAction::run('existing-default', 'Existing Default');

    expect($theme->refresh()->default)->toBeTrue();
});
