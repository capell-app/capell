<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionHealthAlertCategory;
use Capell\Core\Enums\ExtensionHealthAlertSeverity;
use Capell\Core\Enums\ExtensionLicenceStatus;
use Capell\Marketplace\Casts\EncryptedString;
use Capell\Marketplace\Data\ExtensionDetailData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\HeartbeatResultData;
use Capell\Marketplace\Data\MarketplaceInstallAuthorizationData;
use Capell\Marketplace\Models\UpdateAdvisorySnapshot;
use Capell\Marketplace\Support\MarketplacePayloadSigner;
use Capell\Marketplace\Support\MarketplaceWebhookUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

it('normalizes marketplace install authorization payloads', function (): void {
    $authorization = MarketplaceInstallAuthorizationData::fromApiResponse([
        'data' => [
            'composer_name' => 'capell-app/seo-suite',
            'version_constraint' => '^2.1',
            'repository_url' => 'https://github.com/capell-app/seo-suite',
            'composer_auth' => ['github-oauth' => ['github.com' => 'token']],
            'expires_at' => '2026-05-07T10:00:00+00:00',
            'signed_activation' => ['activation_id' => 'act_123', 'expires_at' => '2026-05-07T10:00:00+00:00'],
            'metadata' => ['requires_deployment' => true],
        ],
    ]);

    expect($authorization->toPayload())->toBe([
        'composer_name' => 'capell-app/seo-suite',
        'version_constraint' => '^2.1',
        'repository_url' => 'https://github.com/capell-app/seo-suite',
        'composer_auth' => ['github-oauth' => ['github.com' => 'token']],
        'expires_at' => '2026-05-07T10:00:00+00:00',
        'signed_activation' => ['activation_id' => 'act_123', 'expires_at' => '2026-05-07T10:00:00+00:00'],
        'metadata' => ['requires_deployment' => true],
    ]);

    expect(MarketplaceInstallAuthorizationData::fromApiResponse([])->toPayload())->toMatchArray([
        'composer_name' => '',
        'version_constraint' => '*',
        'repository_url' => null,
        'composer_auth' => null,
        'metadata' => [],
    ]);
});

it('parses health alerts from heartbeat responses', function (): void {
    $result = HeartbeatResultData::fromApiResponse([
        'instance_id' => 'inst_123',
        'alerts' => [[
            'alert_id' => 'alert_123',
            'extension_slug' => 'seo-suite',
            'site_id' => 'site_123',
            'install_id' => 'inst_123',
            'severity' => 'critical',
            'category' => 'security',
            'title' => 'Urgent update required',
            'message' => 'Update Advanced SEO Suite now.',
            'required_action' => 'update_extension',
            'runtime_disabled' => false,
            'protected_actions_blocked' => true,
            'issued_at' => '2026-05-09T10:00:00+00:00',
            'expires_at' => '2026-05-10T10:00:00+00:00',
            'signature' => 'signed-alert',
        ], 'invalid'],
    ]);

    expect($result->alerts)->toHaveCount(1)
        ->and($result->alerts[0]->severity)->toBe(ExtensionHealthAlertSeverity::Critical)
        ->and($result->alerts[0]->category)->toBe(ExtensionHealthAlertCategory::Security)
        ->and($result->alerts[0]->siteId)->toBe('site_123')
        ->and($result->alerts[0]->installId)->toBe('inst_123')
        ->and($result->toArray()['alerts'][0]['signature'])->toBe('signed-alert');
});

it('parses extension detail payloads with conservative collection defaults', function (): void {
    config(['capell-marketplace.marketplace.web_url' => 'https://capell.app']);

    $detail = ExtensionDetailData::fromApiResponse([
        'slug' => 'seo-suite',
        'name' => 'Advanced SEO Suite',
        'composer_name' => 'capell-app/seo-suite',
        'kind' => 'extension',
        'description' => 'SEO tools for Capell.',
        'price_cents' => 4900,
        'is_paid' => true,
        'image_url' => 'docs/assets/marketplace/card.png',
        'images' => [
            ['url' => 'https://example.test/screenshot.png'],
            ['url' => 'docs/assets/marketplace/screenshot.png'],
            ['url' => ''],
            'invalid',
        ],
        'documentation' => 'invalid',
        'version_history' => [
            ['version' => '2.0.0'],
        ],
        'ratings_summary' => ['average' => 4.8],
        'comments_summary' => ['count' => 12],
        'tips_summary' => ['count' => 3],
        'policy' => ['ignored' => true],
        'licence' => [
            'licence_status' => 'active',
            'can_download' => true,
            'runtime_allowed' => true,
        ],
    ]);

    expect($detail->slug)->toBe('seo-suite')
        ->and($detail->priceCents)->toBe(4900)
        ->and($detail->imageUrl)->toBe('https://capell.app/docs/assets/marketplace/card.png')
        ->and($detail->images)->toBe([
            ['url' => 'https://example.test/screenshot.png'],
            ['url' => 'https://capell.app/docs/assets/marketplace/screenshot.png'],
        ])
        ->and($detail->documentation)->toBe([])
        ->and($detail->versionHistory)->toBe([['version' => '2.0.0']])
        ->and($detail->licence?->licenceStatus)->toBe(ExtensionLicenceStatus::Active);
});

it('uses the first listing screenshot as the listing image fallback', function (): void {
    $listing = ExtensionListingData::fromApiResponse([
        'slug' => 'analytics',
        'name' => 'Analytics',
        'composer_name' => 'capell-app/analytics',
        'screenshots' => [
            [
                'path' => '/images/screenshots/analytics.webp',
                'alt' => 'Analytics screenshot',
            ],
        ],
    ]);

    expect($listing->imageUrl)->toBe('/images/screenshots/analytics.webp');
    expect($listing->imageUrls)->toBe(['/images/screenshots/analytics.webp']);
});

it('normalizes stale Capell App marketplace composer names to local package names', function (string $remoteComposerName, string $localComposerName): void {
    $listing = ExtensionListingData::fromApiResponse([
        'slug' => 'stale-package',
        'name' => 'Stale Package',
        'composer_name' => $remoteComposerName,
    ]);

    expect($listing->composerName)->toBe($localComposerName);
})->with([
    'forms' => ['capell-app/forms', 'capell-app/form-builder'],
    'media assistant' => ['capell-app/media-assistant', 'capell-app/media-ai'],
    'migrator' => ['capell-app/migrator', 'capell-app/migration-assistant'],
    'foundation theme' => ['capell-theme/foundation', 'capell-app/foundation-theme'],
    'app theme' => ['capell-theme/agency', 'capell-app/theme-agency'],
]);

it('prefers listing logo artwork before screenshot images', function (): void {
    $listing = ExtensionListingData::fromApiResponse([
        'slug' => 'analytics',
        'name' => 'Analytics',
        'composer_name' => 'capell-app/analytics',
        'image_url' => '/images/screenshots/analytics.webp',
        'logo_url' => '/images/extensions/analytics-card.webp',
    ]);

    expect($listing->imageUrl)->toBe('/images/extensions/analytics-card.webp');
    expect($listing->imageUrls)->toBe([
        '/images/extensions/analytics-card.webp',
        '/images/screenshots/analytics.webp',
    ]);
});

it('prefers listing card artwork before generic image urls', function (): void {
    $listing = ExtensionListingData::fromApiResponse([
        'slug' => 'analytics',
        'name' => 'Analytics',
        'composer_name' => 'capell-app/analytics',
        'image_url' => '/images/screenshots/analytics.webp',
        'card_image_url' => '/images/extensions/analytics-card.webp',
    ]);

    expect($listing->imageUrl)->toBe('/images/extensions/analytics-card.webp');
    expect($listing->imageUrls)->toBe([
        '/images/extensions/analytics-card.webp',
        '/images/screenshots/analytics.webp',
    ]);
});

it('resolves marketplace webhook URLs only from a route or explicit config', function (): void {
    config([
        'capell-marketplace.marketplace.webhook_url' => 'https://hooks.example.test/marketplace',
        'app.url' => 'https://app.example.test/',
    ]);

    expect(MarketplaceWebhookUrl::resolve())->toBe('https://hooks.example.test/marketplace')
        ->and(MarketplaceWebhookUrl::isAvailable())->toBeTrue();

    config(['capell-marketplace.marketplace.webhook_url' => null]);

    expect(MarketplaceWebhookUrl::resolve())->toBeNull()
        ->and(MarketplaceWebhookUrl::isAvailable())->toBeFalse();
});

it('adds a signed nonce to marketplace payloads before signing', function (): void {
    $signer = new MarketplacePayloadSigner;

    $payload = $signer->signedPayload([
        'event_type' => 'extension_health_report',
        'instance_id' => '00000000-0000-4000-8000-000000000001',
    ], 'secret');

    expect($payload['signature_nonce'])->toBeString()
        ->and($payload['signature'])->toBe($signer->signature($payload, 'secret'));
});

it('casts encrypted marketplace strings and returns the latest advisory snapshot', function (): void {
    $cast = new EncryptedString;
    $model = new class extends Model
    {
        use HasFactory;
    };
    $encrypted = $cast->set($model, 'secret', 'plain-secret', []);

    expect($encrypted)->not->toBe('plain-secret')
        ->and($cast->get($model, 'secret', $encrypted, []))->toBe('plain-secret')
        ->and($cast->set($model, 'secret', null, []))->toBeNull()
        ->and($cast->get($model, 'secret', null, []))->toBeNull();

    UpdateAdvisorySnapshot::query()->create([
        'source' => 'old',
        'checked_at' => now()->subDay(),
        'updates' => [['package' => 'old/package']],
        'advisories' => [],
        'metadata' => ['old' => true],
    ]);
    $latest = UpdateAdvisorySnapshot::query()->create([
        'source' => 'heartbeat',
        'checked_at' => now(),
        'updates' => [['package' => 'capell-app/seo-suite']],
        'advisories' => [['package' => 'capell-app/seo-suite']],
        'metadata' => ['instance_id' => 'instance-123'],
    ]);

    expect(UpdateAdvisorySnapshot::latestSnapshot()?->is($latest))->toBeTrue()
        ->and($latest->checked_at)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($latest->updates)->toBe([['package' => 'capell-app/seo-suite']])
        ->and($latest->advisories)->toBe([['package' => 'capell-app/seo-suite']])
        ->and($latest->metadata)->toBe(['instance_id' => 'instance-123']);
});
