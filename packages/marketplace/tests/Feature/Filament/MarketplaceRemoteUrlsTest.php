<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\NotifyMarketplaceCommercialWarningsAction;
use Capell\Marketplace\Actions\NotifyMarketplaceInstallCompletedAction;
use Capell\Marketplace\Actions\RecordUpdateAdvisorySnapshotAction;
use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Filament\Pages\MarketplaceExtensionDetailPage;
use Capell\Marketplace\Filament\Pages\MarketplacePurchasesPage;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

dataset('untrusted remote urls', [
    'script' => 'javascript:alert(1)',
    'data document' => 'data:text/html,<script>alert(1)</script>',
    'plain http' => 'http://marketplace.example/docs',
    'untrusted host' => 'https://evil.example/login',
    'scheme relative' => '//marketplace.example/docs',
]);

beforeEach(function (): void {
    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.example/api',
        'capell-marketplace.marketplace.web_url' => 'https://marketplace.example',
    ]);
});

function remoteUrlsViewer(): void
{
    Permission::findOrCreate(MarketplacePermission::ViewMarketplacePage->value, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(MarketplacePermission::ViewMarketplacePage->value);
}

function remoteDetailResponse(string $documentationUrl): mixed
{
    return Http::response(['data' => [
        'slug' => 'seo-suite',
        'name' => 'Advanced SEO Suite',
        'composer_name' => 'capell-app/seo-suite',
        'kind' => 'plugin',
        'documentation_url' => $documentationUrl,
        'purchase_url' => $documentationUrl,
        'price_cents' => 4900,
        'is_paid' => true,
    ]]);
}

it('renders no documentation link for an untrusted remote detail URL', function (string $url): void {
    remoteUrlsViewer();
    Http::fake(['https://marketplace.example/api/extensions/seo-suite' => remoteDetailResponse($url)]);

    Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'seo-suite'])
        ->assertSuccessful()
        ->assertSee('Advanced SEO Suite')
        ->assertDontSee($url, false)
        ->assertDontSee(__('capell-marketplace::marketplace.detail.docs_link_cta'));
})->with('untrusted remote urls');

it('still renders the documentation link for a trusted Marketplace URL', function (): void {
    remoteUrlsViewer();
    Http::fake(['https://marketplace.example/api/extensions/seo-suite' => remoteDetailResponse('https://marketplace.example/docs/seo-suite')]);

    Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'seo-suite'])
        ->assertSee('href="https://marketplace.example/docs/seo-suite"', false)
        ->assertSee(__('capell-marketplace::marketplace.detail.docs_link_cta'));
});

it('renders no renewal or support link for untrusted heartbeat URLs', function (string $url): void {
    remoteUrlsViewer();
    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000321',
        'signing_secret_encrypted' => 'test-secret',
        'connection_metadata' => ['commercial' => [
            'purchases' => [],
            'renewal_url' => $url,
            'support_url' => $url,
        ]],
        'last_heartbeat_at' => now(),
    ]);
    resolve(MarketplaceInstanceResolver::class)->forget();

    Livewire::test(MarketplacePurchasesPage::class)
        ->assertSuccessful()
        ->assertDontSee($url, false)
        ->assertDontSee(__('capell-marketplace::marketplace.purchases.renew'))
        ->assertDontSee(__('capell-marketplace::marketplace.purchases.support'));
})->with('untrusted remote urls');

it('keeps only trusted notice URLs when recording an advisory snapshot', function (): void {
    $notice = fn (string $url): array => [
        'id' => 'notice-1',
        'title' => 'Blog patch',
        'release_notes_url' => $url,
        'upgrade_guide_url' => 'https://marketplace.example/guides/blog',
        'affected_packages' => [['composer_name' => 'capell-app/blog', 'documentation_url' => $url]],
    ];

    foreach (['javascript:alert(1)', 'data:text/html,x', 'http://marketplace.example/notes', 'https://evil.example/login'] as $url) {
        $snapshot = RecordUpdateAdvisorySnapshotAction::run('heartbeat', [
            'updates' => [$notice($url)],
            'advisories' => [$notice($url)],
        ]);

        expect($snapshot->updates[0]['release_notes_url'])->toBeNull()
            ->and($snapshot->advisories[0]['release_notes_url'])->toBeNull()
            ->and($snapshot->advisories[0]['affected_packages'][0]['documentation_url'])->toBeNull()
            ->and($snapshot->advisories[0]['upgrade_guide_url'])->toBe('https://marketplace.example/guides/blog')
            ->and($snapshot->advisories[0]['title'])->toBe('Blog patch');
    }
});

it('stores remote extension and purchase names escaped in notifications', function (): void {
    Schema::create('notifications', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
    CarbonImmutable::setTestNow('2026-08-05 12:00:00');
    Cache::flush();
    test()->actingAsAdmin();

    NotifyMarketplaceCommercialWarningsAction::run(['purchases' => [[
        'id' => 'purchase-expiring',
        'name' => '<img src=x onerror=alert(1)>Suite',
        'status' => 'active',
        'protected_updates' => true,
        'access_ends_at' => '2026-08-20T00:00:00Z',
    ]]]);

    $stored = json_encode(test()->authenticatedUser()->notifications->map->getAttribute('data')->all(), JSON_THROW_ON_ERROR);

    expect($stored)->toContain('&lt;img')
        ->and($stored)->not->toContain('<img');
});

it('escapes a remote extension name in the install completed notification body', function (): void {
    Schema::create('notifications', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
    test()->actingAsAdmin();

    $attempt = new MarketplaceInstallAttempt([
        'composer_name' => 'capell-app/evil',
        'extension_name' => '<a href="https://evil.example">Click</a>',
    ]);
    $attempt->user_id = test()->authenticatedUser()->getKey();

    NotifyMarketplaceInstallCompletedAction::run($attempt);

    $stored = json_encode(test()->authenticatedUser()->notifications->map->getAttribute('data')->all(), JSON_THROW_ON_ERROR);

    expect($stored)->toContain('&lt;a href')
        ->and($stored)->not->toContain('<a href');
});

dataset('first party urls', [
    'apex' => ['https://capell.app/x', true],
    'subdomain' => ['https://docs.capell.app/x', true],
    'mixed case trailing dot' => ['https://DOCS.Capell.App./x', true],
    'explicit 443' => ['https://docs.capell.app:443/x', true],
    'suffix lookalike' => ['https://capell.app.evil.com/x', false],
    'prefix lookalike' => ['https://evilcapell.app/x', false],
    'userinfo' => ['https://capell.app@evil.com/x', false],
    'host in path' => ['https://evil.com/capell.app', false],
    'host in query' => ['https://evil.com/?u=https://capell.app', false],
    'other port' => ['https://capell.app:8443/x', false],
    'plain http' => ['http://docs.capell.app/x', false],
    'punycode lookalike' => ['https://xn--capell-6va.app/x', false],
    'scheme relative' => ['//docs.capell.app/x', false],
    'ip literal' => ['https://127.0.0.1/x', false],
]);

it('limits first-party links to the configured trusted hosts on every remote path', function (string $url, bool $allowed): void {
    remoteUrlsViewer();
    config(['capell-marketplace.marketplace.web_url' => 'https://marketplace.example']);

    Http::fake(['https://marketplace.example/api/extensions/seo-suite' => remoteDetailResponse($url)]);
    $detail = Livewire::test(MarketplaceExtensionDetailPage::class, ['slug' => 'seo-suite']);

    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000654',
        'signing_secret_encrypted' => 'test-secret',
        'connection_metadata' => ['commercial' => ['purchases' => [], 'renewal_url' => $url, 'support_url' => $url]],
        'last_heartbeat_at' => now(),
    ]);
    resolve(MarketplaceInstanceResolver::class)->forget();
    $purchases = Livewire::test(MarketplacePurchasesPage::class);

    $snapshot = RecordUpdateAdvisorySnapshotAction::run('heartbeat', ['advisories' => [['release_notes_url' => $url]]]);

    if ($allowed) {
        $detail->assertSee(__('capell-marketplace::marketplace.detail.docs_link_cta'));
        $purchases->assertSee(__('capell-marketplace::marketplace.purchases.renew'));
        expect($snapshot->advisories[0]['release_notes_url'])->toBe($url);

        return;
    }

    $detail->assertDontSee(__('capell-marketplace::marketplace.detail.docs_link_cta'));
    $purchases->assertDontSee(__('capell-marketplace::marketplace.purchases.renew'));
    expect($snapshot->advisories[0]['release_notes_url'])->toBeNull();
})->with('first party urls');

it('follows the trusted host list in configuration', function (): void {
    remoteUrlsViewer();
    $url = 'https://docs.partner.example/x';

    expect(RecordUpdateAdvisorySnapshotAction::run('heartbeat', ['advisories' => [['release_notes_url' => $url]]])->advisories[0]['release_notes_url'])->toBeNull();

    config(['capell-marketplace.marketplace.trusted_hosts' => ['partner.example']]);
    expect(RecordUpdateAdvisorySnapshotAction::run('heartbeat', ['advisories' => [['release_notes_url' => $url]]])->advisories[0]['release_notes_url'])->toBe($url);

    config(['capell-marketplace.marketplace.trusted_hosts' => []]);
    expect(RecordUpdateAdvisorySnapshotAction::run('heartbeat', ['advisories' => [['release_notes_url' => 'https://docs.capell.app/x']]])->advisories[0]['release_notes_url'])->toBeNull();
});
