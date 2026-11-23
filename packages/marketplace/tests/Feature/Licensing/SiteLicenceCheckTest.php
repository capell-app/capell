<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Actions\CheckSiteLicenceAction;
use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Enums\SiteLicenceState;
use Capell\Marketplace\Support\Licensing\NonProductionDomain;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

const SITE_LICENCE_URL = 'https://marketplace.example.test/api/v1/site-licences/activate';

function installPaidPackage(string $composerName = 'capell-app/form-builder'): void
{
    CapellExtension::query()->create([
        'composer_name' => $composerName, 'name' => $composerName,
        'status' => ExtensionStatusEnum::Enabled, 'is_paid_marketplace_extension' => true,
    ]);
}

beforeEach(function (): void {
    config([
        'app.url' => 'https://client.com',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.example.test/api/v1',
        'capell-marketplace.licence.keys' => ['capell_live_key'],
    ]);
    Cache::forget(CheckSiteLicenceAction::CACHE_KEY);
});

// Catches the check sending anything beyond the domain and key the licence terms disclose.
it('sends only the site domain and licence key and records a licensed site', function (): void {
    installPaidPackage();
    Http::fake([SITE_LICENCE_URL => Http::response(['data' => [
        'status' => 'active', 'domain' => 'client.com', 'sitesUsed' => 1, 'siteLimit' => 3, 'packages' => ['capell-app/form-builder'],
    ]])]);

    $status = resolve(CheckSiteLicenceAction::class)->handle();

    expect($status?->state)->toBe(SiteLicenceState::Licensed);
    Http::assertSent(fn (Request $request): bool => $request->url() === SITE_LICENCE_URL
        && $request->data() === ['domain' => 'client.com', 'key' => 'capell_live_key']);
    Http::assertSentCount(1);
});

// Catches local, staging and free-only sites sending a licence check at all.
it('sends nothing for a site with no paid packages or on a local or staging host', function (?string $appUrl, bool $paid, ?SiteLicenceState $expected): void {
    if ($paid) {
        installPaidPackage();
    }

    if ($appUrl !== null) {
        config(['app.url' => $appUrl]);
    }

    Http::fake();

    expect(resolve(CheckSiteLicenceAction::class)->handle()?->state)->toBe($expected);
    Http::assertNothingSent();
})->with([
    'free packages only' => [null, false, null],
    'staging host' => ['https://staging.client.com', true, SiteLicenceState::NonProduction],
    'no site address configured' => ['', true, SiteLicenceState::NonProduction],
    'local host' => ['http://client.test', true, SiteLicenceState::NonProduction],
]);

it('asks for a key when paid packages are installed without one, sending nothing', function (): void {
    installPaidPackage();
    config(['capell-marketplace.licence.keys' => []]);
    Http::fake();

    $status = resolve(CheckSiteLicenceAction::class)->handle();

    expect($status?->state)->toBe(SiteLicenceState::MissingKey)->and($status?->unlicensedPackages)->toBe(['capell-app/form-builder']);
    Http::assertNothingSent();
});

// Catches a third-party paid package the key does not cover going unnoticed.
it('names paid packages the key does not cover and reports a full licence', function (): void {
    installPaidPackage();
    installPaidPackage('acme/paid-widget');
    Http::fake([SITE_LICENCE_URL => Http::response(['data' => [
        'status' => 'over_limit', 'domain' => 'client.com', 'sitesUsed' => 3, 'siteLimit' => 3, 'packages' => ['capell-app/form-builder'],
    ]])]);

    $status = resolve(CheckSiteLicenceAction::class)->handle();

    expect($status?->state)->toBe(SiteLicenceState::OverLimit)
        ->and($status?->unlicensedPackages)->toBe(['acme/paid-widget', 'capell-app/form-builder'])
        ->and($status?->siteLimit)->toBe(3);
});

// Catches an unreachable marketplace turning a licensed site into a nagged one.
it('keeps the last answer when the marketplace cannot be reached', function (): void {
    installPaidPackage();
    Http::fake([SITE_LICENCE_URL => Http::response(['data' => [
        'status' => 'active', 'domain' => 'client.com', 'sitesUsed' => 1, 'siteLimit' => 3, 'packages' => ['capell-app/form-builder'],
    ]])]);
    resolve(CheckSiteLicenceAction::class)->handle();
    Http::fake([SITE_LICENCE_URL => Http::response('down', 503)]);

    expect(resolve(CheckSiteLicenceAction::class)->handle()?->state)->toBe(SiteLicenceState::Licensed);
});

// Catches the notice missing from admin, or leaking to people who cannot act on it or onto public pages.
it('shows the licence notice only inside the admin panel to people who manage extensions', function (): void {
    installPaidPackage();
    config(['capell-marketplace.licence.keys' => []]);
    Http::fake();
    resolve(CheckSiteLicenceAction::class)->handle();
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);

    $this->get('/admin')->assertOk()->assertSee('data-capell-site-licence-notice="missing_key"', false);
    $this->get('/')->assertDontSee('data-capell-site-licence-notice', false);
});

it('classifies local and staging hosts the same way capell.app does', function (string $host, bool $free): void {
    expect(NonProductionDomain::is($host))->toBe($free);
})->with([
    ['localhost', true], ['shop.test', true], ['192.168.1.20', true], ['staging.client.co.uk', true],
    ['pr-12-preview.client.com', true], ['client.com', false], ['dev.to', false], ['devstudio.com', false],
]);
