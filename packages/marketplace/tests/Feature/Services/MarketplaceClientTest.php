<?php

declare(strict_types=1);

use Capell\Marketplace\Data\MarketplaceCatalogueQueryData;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Exceptions\PurchaseRequiredException;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplacePayloadSigner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config([
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.cache_ttl_seconds' => 300,
        'capell-marketplace.marketplace.timeout_seconds' => 10,
    ]);
});

function signedMarketplaceAlert(array $alert, string $secret): array
{
    $alert['signature'] = hash_hmac('sha256', marketplaceClientCanonicalJson($alert), $secret);

    return $alert;
}

function marketplaceClientCanonicalJson(array $payload): string
{
    unset($payload['signature']);

    return json_encode(marketplaceClientSortRecursively($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function marketplaceClientSortRecursively(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (! array_is_list($value)) {
        ksort($value);
    }

    foreach ($value as $key => $nestedValue) {
        $value[$key] = marketplaceClientSortRecursively($nestedValue);
    }

    return $value;
}

it('creates an account connection session through the marketplace api', function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1']);

    Http::fake([
        'https://capell.test/api/v1/marketplace/connections' => Http::response([
            'data' => [
                'connection_session_id' => 'mcs_123',
                'approval_url' => 'https://capell.test/marketplace/connect/mcs_123',
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
            ],
        ], 201),
    ]);

    $response = resolve(MarketplaceClient::class)->createAccountConnectionSession([
        'app_url' => 'http://capell-ruby.test',
        'callback_url' => 'http://capell-ruby.test/admin/marketplace/connection/callback',
    ]);

    expect($response['connection_session_id'])->toBe('mcs_123')
        ->and($response['approval_url'])->toBe('https://capell.test/marketplace/connect/mcs_123');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://capell.test/api/v1/marketplace/connections'
        && ! array_key_exists('claimed_domain', $request->data())
        && $request->data()['app_url'] === 'http://capell-ruby.test');
});

it('does not sign install flow requests using fallback instance credentials', function (): void {
    config([
        'capell-marketplace.instance.id' => '00000000-0000-4000-8000-000000000127',
        'capell-marketplace.marketplace.webhook_secret' => 'stale-secret',
    ]);

    Http::fake([
        'https://marketplace.test/api/marketplace/install-flows' => Http::response([
            'data' => [
                'flow_id' => 'mif_unsigned',
                'approval_url' => 'https://marketplace.test/marketplace/install-flows/mif_unsigned',
            ],
        ], 201),
    ]);

    resolve(MarketplaceClient::class)->createInstallFlow([
        'state' => 'state_123',
        'return_url' => 'http://capell-ruby.test/admin/marketplace/install-flow/callback',
    ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/marketplace/install-flows'
        && ! array_key_exists('instance_id', $request->data())
        && ! array_key_exists('signature', $request->data())
        && ! array_key_exists('signature_algorithm', $request->data())
        && ! array_key_exists('signature_context', $request->data()));
});

it('rejects install flow approval redirects with a mismatched scheme or port', function (string $approvalUrl): void {
    Http::fake([
        'https://marketplace.test/api/marketplace/install-flows' => Http::response([
            'data' => [
                'flow_id' => 'mif_invalid_origin',
                'approval_url' => $approvalUrl,
            ],
        ], 201),
    ]);

    expect(fn (): array => resolve(MarketplaceClient::class)->createInstallFlow([
        'state' => 'state_123',
        'return_url' => 'http://capell-ruby.test/admin/marketplace/install-flow/callback',
    ]))->toThrow(RuntimeException::class, 'Marketplace returned an invalid approval URL.');
})->with([
    'scheme downgrade' => ['http://marketplace.test/marketplace/install-flows/mif_invalid_origin'],
    'unexpected port' => ['https://marketplace.test:8443/marketplace/install-flows/mif_invalid_origin'],
]);

it('signs install flow requests with a stored marketplace instance', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000128',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email' => 'ben@example.com',
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/marketplace/install-flows' => Http::response([
            'data' => [
                'flow_id' => 'mif_signed',
                'approval_url' => 'https://marketplace.test/marketplace/install-flows/mif_signed',
            ],
        ], 201),
    ]);

    resolve(MarketplaceClient::class)->createInstallFlow([
        'state' => 'state_123',
        'return_url' => 'http://capell-ruby.test/admin/marketplace/install-flow/callback',
    ]);

    Http::assertSent(function ($request): bool {
        $payload = $request->data();
        $expectedSignature = resolve(MarketplacePayloadSigner::class)->signature($payload, 'secret-value');

        return $request->url() === 'https://marketplace.test/api/marketplace/install-flows'
            && $payload['instance_id'] === '00000000-0000-4000-8000-000000000128'
            && $payload['account_id'] === 'acct_123'
            && $payload['signature'] === $expectedSignature
            && $payload['signature_context'] === [
                'method' => 'POST',
                'path' => '/marketplace/install-flows',
                'instance_id' => '00000000-0000-4000-8000-000000000128',
            ];
    });
});

it('caches extension detail responses by marketplace context', function (): void {
    Cache::flush();

    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000125',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email' => 'ben@example.com',
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite*' => Http::response([
            'data' => [
                'slug' => 'seo-suite',
                'name' => 'Advanced SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'kind' => 'plugin',
                'price_cents' => 4900,
                'is_paid' => true,
            ],
        ]),
    ]);

    $client = resolve(MarketplaceClient::class);

    expect($client->getExtensionDetail('seo-suite')?->name)->toBe('Advanced SEO Suite')
        ->and($client->getExtensionDetail('seo-suite')?->name)->toBe('Advanced SEO Suite');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/seo-suite'
            && $request->hasHeader('X-Capell-Instance', '00000000-0000-4000-8000-000000000125')
            && $request->hasHeader('X-Capell-Account', 'acct_123')
            && ! $request->hasHeader('X-Capell-Domain')
            && ! $request->hasHeader('X-Capell-Publicly-Verified-Domains')
            && ! $request->hasHeader('X-Capell-Marketplace-Capabilities'));
});

it('rejects marketplace extension slugs with path or query delimiters', function (): void {
    resolve(MarketplaceClient::class)->getExtensionDetail('../admin?debug=1');
})->throws(InvalidArgumentException::class);

it('exchanges an account connection code through the marketplace api', function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1']);

    Http::fake([
        'https://capell.test/api/v1/marketplace/connections/exchange' => Http::response([
            'data' => [
                'instance_id' => '00000000-0000-4000-8000-000000000123',
                'signing_secret' => 'secret-value',
                'account_id' => 'acct_123',
                'account_name' => 'Ben Johnson',
                'account_email' => 'ben@example.com',
                'account_email_verified_at' => now()->toIso8601String(),
                'diagnostics_summary' => ['publicly_verifiable' => false],
            ],
        ]),
    ]);

    $response = resolve(MarketplaceClient::class)->exchangeAccountConnectionCode([
        'connection_session_id' => 'mcs_123',
        'code' => 'code_123',
        'state' => 'state_123',
        'code_verifier' => 'verifier_123',
    ]);

    expect($response['instance_id'])->toBe('00000000-0000-4000-8000-000000000123')
        ->and($response['account_email'])->toBe('ben@example.com');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://capell.test/api/v1/marketplace/connections/exchange'
        && $request->data()['connection_session_id'] === 'mcs_123'
        && $request->data()['code_verifier'] === 'verifier_123');
});

it('lists extensions across marketplace pages with normalized filters', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::sequence()
            ->push([
                'data' => [[
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                    'kind' => 'plugin',
                    'description' => 'Search visibility tooling.',
                    'price_cents' => 1200,
                    'is_paid' => true,
                    'latest_version' => '1.2.3',
                    'released_at' => '2026-05-01T10:00:00+00:00',
                    'capabilities' => ['settings' => true],
                    'categories' => ['seo', '', 42],
                    'purchase_url' => 'https://marketplace.test/checkout/seo-suite',
                    'image_url' => 'https://marketplace.test/images/seo-suite.png',
                    'publisher_verified' => true,
                    'security_reviewed' => true,
                    'display_name' => 'Advanced SEO Suite',
                    'product' => ['tier' => 'premium', 'bundle' => 'growth', 'group' => 'Marketing'],
                    'commercial' => ['requestedCertification' => 'first-party', 'supportPolicy' => 'priority'],
                    'private_docs_entitled' => true,
                    'performance' => ['frontendRenderBudgetMs' => 20, 'cacheTags' => ['extension:seo-suite']],
                    'contribution_summary' => ['admin-page' => 1, 'frontend-component' => 2],
                    'install_eligibility' => 'blocked',
                    'blocked_reason' => 'Domain validation required',
                    'next_action' => 'Validate domain',
                    'surfaces' => ['admin', 'frontend'],
                    'dependencies' => ['requires' => ['capell-app/html-cache']],
                ]],
                'links' => ['next' => 'https://marketplace.test/api/extensions?page=2'],
            ])
            ->push([
                'data' => [[
                    'slug' => 'migration-assistant',
                    'name' => 'Migration Assistant',
                    'composer_name' => 'capell-app/migration-assistant',
                    'kind' => 'tool',
                    'price_cents' => 0,
                    'is_paid' => false,
                ]],
                'links' => ['next' => null],
            ]),
    ]);

    $extensions = resolve(MarketplaceClient::class)->listExtensions(
        search: 'seo',
        kind: 'plugin',
        freeOnly: true,
        sort: 'price_low_high',
        priceMinCents: 100,
        priceMaxCents: 2000,
        capellVersion: '4.0.0',
        laravelVersion: '12.0.0',
        livewireVersion: '3.0.0',
        filamentVersion: '4.0.0',
        category: 'seo',
        capabilities: ['settings', 'blocks'],
    );

    expect($extensions)->toHaveCount(2)
        ->and($extensions[0]->slug)->toBe('seo-suite')
        ->and($extensions[0]->composerName)->toBe('capell-app/seo-suite')
        ->and($extensions[0]->releasedAt?->toDateString())->toBe('2026-05-01')
        ->and($extensions[0]->categories)->toBe(['seo', '42'])
        ->and($extensions[0]->publisherVerified)->toBeTrue()
        ->and($extensions[0]->securityReviewed)->toBeTrue()
        ->and($extensions[0]->displayName)->toBe('Advanced SEO Suite')
        ->and($extensions[0]->productTier)->toBe('premium')
        ->and($extensions[0]->productBundle)->toBe('growth')
        ->and($extensions[0]->effectiveCertification)->toBe('first-party')
        ->and($extensions[0]->supportPolicy)->toBe('priority')
        ->and($extensions[0]->privateDocsEntitled)->toBeTrue()
        ->and($extensions[0]->performanceBudget['frontendRenderBudgetMs'] ?? null)->toBe(20)
        ->and($extensions[0]->contributionSummary['frontend-component'] ?? null)->toBe(2)
        ->and($extensions[0]->installEligibility)->toBe('blocked')
        ->and($extensions[0]->blockedReason)->toBe('Domain validation required')
        ->and($extensions[0]->nextAction)->toBe('Validate domain')
        ->and($extensions[0]->surfaces)->toBe(['admin', 'frontend'])
        ->and($extensions[0]->requiredDependencies)->toBe(['capell-app/html-cache'])
        ->and($extensions[1]->slug)->toBe('migration-assistant');

    Http::assertSent(fn ($request): bool => array_key_exists('search', $request->data())
        && str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && $request->data()['search'] === 'seo'
        && $request->data()['kind'] === 'plugin'
        && $request->data()['free'] === '1'
        && $request->data()['sort'] === 'price_low_high'
        && $request->data()['min_price_cents'] === '100'
        && $request->data()['max_price_cents'] === '2000'
        && $request->data()['capell_version'] === '4.0.0'
        && $request->data()['laravel_version'] === '12.0.0'
        && $request->data()['livewire_version'] === '3.0.0'
        && $request->data()['filament_version'] === '4.0.0'
        && $request->data()['category'] === 'seo'
        && $request->data()['capabilities'] === 'settings,blocks');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?page=2'));
});

it('fetches marketplace extensions by exact composer names', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions/by-composer*' => Http::response([
            'data' => [
                [
                    'slug' => 'html-cache',
                    'name' => 'HTML Cache',
                    'composer_name' => 'capell-app/html-cache',
                    'kind' => 'plugin',
                    'price_cents' => 0,
                    'is_paid' => false,
                ],
                [
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                    'kind' => 'plugin',
                    'price_cents' => 4900,
                    'is_paid' => true,
                ],
            ],
        ]),
    ]);

    $extensions = resolve(MarketplaceClient::class)->extensionsByComposerNames(
        composerNames: ['capell-app/seo-suite', 'capell-app/html-cache'],
        kind: 'plugin',
        capellVersion: '4.0.0',
        laravelVersion: '12.0.0',
        livewireVersion: '3.0.0',
        filamentVersion: '4.0.0',
    );

    expect(array_keys($extensions))->toBe([
        'capell-app/html-cache',
        'capell-app/seo-suite',
    ])
        ->and($extensions['capell-app/html-cache']->displayName)->toBe('HTML Cache')
        ->and($extensions['capell-app/seo-suite']->displayName)->toBe('SEO Suite');

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions/by-composer?')
        && $request->data()['composer_names'] === 'capell-app/html-cache,capell-app/seo-suite'
        && $request->data()['kind'] === 'plugin'
        && $request->data()['capell_version'] === '4.0.0'
        && $request->data()['laravel_version'] === '12.0.0'
        && $request->data()['livewire_version'] === '3.0.0'
        && $request->data()['filament_version'] === '4.0.0');
});

it('returns no exact composer records when the marketplace lookup endpoint is not available', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions/by-composer*' => Http::response([], 404),
    ]);

    $extensions = resolve(MarketplaceClient::class)->extensionsByComposerNames([
        'capell-app/html-cache',
    ]);

    expect($extensions)->toBe([]);
});

it('parses manifest v3 detail fields for marketplace extension detail responses', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => Http::response([
            'data' => [
                'slug' => 'seo-suite',
                'name' => 'SEO Suite',
                'display_name' => 'Advanced SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'kind' => 'package',
                'description' => 'SEO tools for Capell.',
                'price_cents' => 4900,
                'is_paid' => true,
                'product' => ['group' => 'Marketing', 'tier' => 'premium', 'bundle' => 'growth'],
                'commercial' => [
                    'requestedCertification' => 'first-party',
                    'supportPolicy' => 'priority',
                    'privateDocsRequested' => true,
                ],
                'capabilities' => ['seo' => true, 'schema' => true],
                'categories' => ['seo', 'content'],
                'surfaces' => ['admin', 'frontend'],
                'dependencies' => ['requires' => ['capell-app/html-cache']],
                'performance' => [
                    'frontendRenderBudgetMs' => 15,
                    'cacheTags' => ['extension:seo-suite'],
                ],
                'contribution_summary' => [
                    'admin-page' => 1,
                    'frontend-component' => 2,
                ],
                'install_eligibility' => 'blocked',
                'blocked_reason' => 'Domain validation required',
                'next_action' => 'Validate site domain',
                'health_status' => 'warning',
                'private_docs_entitled' => true,
                'unknown_marketplace_flag' => 'kept',
            ],
        ]),
    ]);

    $detail = resolve(MarketplaceClient::class)->getExtensionDetail('seo-suite');

    expect($detail?->displayName)->toBe('Advanced SEO Suite')
        ->and($detail?->productGroup)->toBe('Marketing')
        ->and($detail?->productTier)->toBe('premium')
        ->and($detail?->productBundle)->toBe('growth')
        ->and($detail?->effectiveCertification)->toBe('first-party')
        ->and($detail?->supportPolicy)->toBe('priority')
        ->and($detail?->privateDocsEntitled)->toBeTrue()
        ->and($detail?->surfaces)->toBe(['admin', 'frontend'])
        ->and($detail?->requiredDependencies)->toBe(['capell-app/html-cache'])
        ->and($detail?->performanceBudget['frontendRenderBudgetMs'] ?? null)->toBe(15)
        ->and($detail?->contributionSummary['frontend-component'] ?? null)->toBe(2)
        ->and($detail?->installEligibility)->toBe('blocked')
        ->and($detail?->blockedReason)->toBe('Domain validation required')
        ->and($detail?->nextAction)->toBe('Validate site domain')
        ->and($detail?->healthStatus)->toBe('warning')
        ->and($detail?->metadata['unknown_marketplace_flag'] ?? null)->toBe('kept');
});

it('can cap marketplace catalogue pagination for modal browsing', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::sequence()
            ->push([
                'data' => [[
                    'slug' => 'seo-suite',
                    'name' => 'SEO Suite',
                    'composer_name' => 'capell-app/seo-suite',
                    'kind' => 'plugin',
                    'price_cents' => 0,
                    'is_paid' => false,
                ]],
                'links' => ['next' => 'https://marketplace.test/api/extensions?page=2'],
            ])
            ->push([
                'data' => [[
                    'slug' => 'migration-assistant',
                    'name' => 'Migration Assistant',
                    'composer_name' => 'capell-app/migration-assistant',
                    'kind' => 'tool',
                    'price_cents' => 0,
                    'is_paid' => false,
                ]],
                'links' => ['next' => null],
            ]),
    ]);

    $extensions = resolve(MarketplaceClient::class)->listExtensions(maxPages: 1);

    expect($extensions)->toHaveCount(1)
        ->and($extensions[0]->slug)->toBe('seo-suite');

    Http::assertNotSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?page=2'));
});

it('requests marketplace catalogue responses as json', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [[
                'slug' => 'seo-suite',
                'name' => 'SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'kind' => 'plugin',
                'price_cents' => 0,
                'is_paid' => false,
            ]],
            'links' => ['next' => null],
        ]),
    ]);

    resolve(MarketplaceClient::class)->listExtensions(maxPages: 1);

    Http::assertSent(fn ($request): bool => $request->hasHeader('Accept', 'application/json'));
});

it('loads one marketplace catalogue page with remote pagination metadata', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [[
                'slug' => 'seo-suite',
                'name' => 'SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'kind' => 'plugin',
                'price_cents' => 0,
                'is_paid' => false,
            ]],
            'links' => ['next' => 'https://marketplace.test/api/extensions?page=3'],
            'meta' => [
                'current_page' => 2,
                'per_page' => 18,
                'total' => 37,
            ],
        ]),
    ]);

    $page = resolve(MarketplaceClient::class)->listExtensionPage(new MarketplaceCatalogueQueryData(
        search: 'seo',
        page: 2,
        perPage: 18,
    ));

    expect($page->extensions)->toHaveCount(1)
        ->and($page->extensions[0]->slug)->toBe('seo-suite')
        ->and($page->currentPage)->toBe(2)
        ->and($page->perPage)->toBe(18)
        ->and($page->total)->toBe(37)
        ->and($page->nextPageUrl)->toBe('https://marketplace.test/api/extensions?page=3')
        ->and($page->stale)->toBeFalse();

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && $request->data()['search'] === 'seo'
        && $request->data()['page'] === '2'
        && $request->data()['per_page'] === '18');
});

it('serves stale marketplace catalogue pages without blocking on a fresh request', function (): void {
    config([
        'capell-marketplace.marketplace.cache_ttl_seconds' => 0,
        'capell-marketplace.marketplace.stale_cache_ttl_seconds' => 300,
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [[
                'slug' => 'cached-suite',
                'name' => 'Cached Suite',
                'composer_name' => 'capell-app/cached-suite',
                'kind' => 'plugin',
                'price_cents' => 0,
                'is_paid' => false,
            ]],
            'links' => ['next' => null],
            'meta' => [
                'current_page' => 1,
                'per_page' => 9,
                'total' => 1,
            ],
        ]),
    ]);

    $query = new MarketplaceCatalogueQueryData(search: 'cached');
    resolve(MarketplaceClient::class)->listExtensionPage($query);

    $page = resolve(MarketplaceClient::class)->listExtensionPage($query, allowStale: true);

    expect($page->extensions)->toHaveCount(1)
        ->and($page->extensions[0]->slug)->toBe('cached-suite')
        ->and($page->stale)->toBeTrue();

    Http::assertSentCount(1);
});

it('rejects successful marketplace catalogue responses without json extension data', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response('<html><title>Capell</title></html>', 200, [
            'Content-Type' => 'text/html',
        ]),
    ]);

    expect(fn (): array => resolve(MarketplaceClient::class)->listExtensions(maxPages: 1))
        ->toThrow(RuntimeException::class, 'The marketplace catalogue did not return JSON extension data.');
});

it('sends connected instance context when listing extensions', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000123',
        'signing_secret_encrypted' => 'secret-value',
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
        ]),
    ]);

    resolve(MarketplaceClient::class)->listExtensions();

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && $request->data()['instance_id'] === '00000000-0000-4000-8000-000000000123'
        && ! array_key_exists('domain', $request->data())
        && ! array_key_exists('publicly_verified_domains', $request->data()));
});

it('does not send invalid instance context when listing extensions', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-widget-domain-validation',
        'signing_secret_encrypted' => 'secret-value',
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
        ]),
    ]);

    resolve(MarketplaceClient::class)->listExtensions();

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && ! array_key_exists('instance_id', $request->data())
        && ! array_key_exists('domain', $request->data())
        && ! array_key_exists('publicly_verified_domains', $request->data()));
});

it('sends account identity context when listing extensions', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000124',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email' => 'ben@example.com',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
        ]),
    ]);

    resolve(MarketplaceClient::class)->listExtensions();

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://marketplace.test/api/extensions?')
        && $request->data()['instance_id'] === '00000000-0000-4000-8000-000000000124'
        && $request->data()['account_id'] === 'acct_123'
        && ! array_key_exists('domain', $request->data())
        && ! array_key_exists('claimed_domains', $request->data()));
});

it('normalizes successful heartbeat responses from marketplace', function (): void {
    Http::fake([
        'https://marketplace.test/api/instances/heartbeat' => Http::response([
            'data' => [
                'instance_id' => 'instance-123',
                'signing_secret' => 'new-secret',
                'checked_at' => '2026-05-07T09:30:00+00:00',
                'capell_version' => '4.0.0',
                'response_id' => 'response-123',
                'updates' => [
                    ['composer_name' => 'capell-app/seo-suite', 'latest_version' => '1.2.3'],
                ],
                'advisories' => [
                    ['composer_name' => 'capell-app/migration-assistant', 'severity' => 'medium'],
                ],
            ],
        ]),
    ]);

    $result = resolve(MarketplaceClient::class)->heartbeat([
        'instance_id' => 'instance-123',
        'app_url' => 'https://example.com',
    ]);

    expect($result->instanceId)->toBe('instance-123')
        ->and($result->signingSecret)->toBe('new-secret')
        ->and($result->updates[0]->toArray())->toBe([
            'composer_name' => 'capell-app/seo-suite',
            'latest_version' => '1.2.3',
        ])
        ->and($result->advisories[0]->toArray())->toBe([
            'composer_name' => 'capell-app/migration-assistant',
            'severity' => 'medium',
        ])
        ->and($result->toArray())->toMatchArray([
            'checked_at' => '2026-05-07T09:30:00+00:00',
            'capell_version' => '4.0.0',
            'response_id' => 'response-123',
            'instance_id' => 'instance-123',
        ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/instances/heartbeat'
        && $request->data()['app_url'] === 'https://example.com'
        && ! array_key_exists('domain', $request->data()));
});

it('includes useful details when marketplace heartbeat returns html', function (): void {
    Http::fake([
        'https://marketplace.test/api/instances/heartbeat' => Http::response(
            '<html><head><title>Maintenance Mode</title></head><body>Down</body></html>',
            503,
            ['content-type' => 'text/html; charset=UTF-8'],
        ),
    ]);

    resolve(MarketplaceClient::class)->heartbeat(['domain' => 'example.com']);
})->throws(RuntimeException::class, 'Page title: Maintenance Mode');

it('fails closed when marketplace heartbeat responses have an invalid payload shape', function (array $responseData, string $message): void {
    Http::fake([
        'https://marketplace.test/api/instances/heartbeat' => Http::response([
            'data' => $responseData,
        ]),
    ]);

    expect(fn () => resolve(MarketplaceClient::class)->heartbeat([
        'instance_id' => 'instance-123',
        'app_url' => 'https://example.com',
    ]))->toThrow(RuntimeException::class, $message);
})->with([
    'missing instance id' => [
        [
            'updates' => [],
            'advisories' => [],
        ],
        'The marketplace response did not include an instance ID.',
    ],
    'invalid signing secret' => [
        [
            'instance_id' => 'instance-123',
            'signing_secret' => '',
            'updates' => [],
            'advisories' => [],
        ],
        'The marketplace response included an invalid signing secret.',
    ],
    'invalid notice lists' => [
        [
            'instance_id' => 'instance-123',
            'updates' => 'latest',
            'advisories' => [],
        ],
        'The marketplace response did not include update and advisory lists.',
    ],
    'invalid update notice' => [
        [
            'instance_id' => 'instance-123',
            'updates' => ['capell-app/seo-suite'],
            'advisories' => [],
        ],
        'The marketplace response included an invalid update notice.',
    ],
    'invalid advisory notice' => [
        [
            'instance_id' => 'instance-123',
            'updates' => [],
            'advisories' => ['capell-app/seo-suite'],
        ],
        'The marketplace response included an invalid advisory notice.',
    ],
]);

it('rejects heartbeat responses without a data object before trusting runtime policy data', function (): void {
    Http::fake([
        'https://marketplace.test/api/instances/heartbeat' => Http::response([
            'meta' => [
                'policy' => [
                    'disable_extensions' => ['capell-app/seo-suite'],
                ],
            ],
        ]),
    ]);

    expect(fn () => resolve(MarketplaceClient::class)->heartbeat([
        'instance_id' => 'instance-123',
        'app_url' => 'https://example.com',
    ]))->toThrow(RuntimeException::class, 'The marketplace response did not include the expected data payload.');
});

it('logs rejected install intent telemetry without failing the local install pipeline', function (): void {
    config([
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.webhook_secret' => 'secret-value',
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->with('capell-marketplace: install intent telemetry was rejected', [
            'status' => 422,
            'composer_name' => 'capell-app/seo-suite',
        ]);

    Http::fake([
        'https://marketplace.test/api/extensions/install-intents' => Http::response([
            'message' => 'Validation failed.',
        ], 422),
    ]);

    resolve(MarketplaceClient::class)->recordInstallIntent([
        'composer_name' => 'capell-app/seo-suite',
    ]);
});

it('trusts only verified heartbeat alerts when building runtime policy', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'secret-value',
        'last_heartbeat_at' => now(),
    ]);

    $verifiedAlert = signedMarketplaceAlert([
        'alert_id' => 'licence-instance-123-paid-suite-example.com',
        'extension_slug' => 'paid-suite',
        'composer_name' => 'capell-app/paid-suite',
        'site_id' => 42,
        'install_id' => 'instance-123',
        'severity' => 'critical',
        'category' => 'licence',
        'title' => 'Paid extension cannot be verified',
        'message' => 'This paid extension is installed without a valid verified-site licence.',
        'required_action' => 'verify_site_or_buy_licence',
        'runtime_disabled' => true,
        'protected_actions_blocked' => true,
        'reason' => 'licence_required',
        'issued_at' => '2026-05-09T10:00:00+00:00',
        'expires_at' => '2026-05-10T10:00:00+00:00',
    ], 'secret-value');

    $tamperedAlert = [
        ...$verifiedAlert,
        'alert_id' => 'tampered-alert',
        'composer_name' => 'capell-app/tampered-suite',
        'signature' => 'invalid',
    ];

    Http::fake([
        'https://marketplace.test/api/instances/heartbeat' => Http::response([
            'data' => [
                'instance_id' => 'instance-123',
                'updates' => [],
                'advisories' => [],
                'alerts' => [$verifiedAlert, $tamperedAlert],
                'policy' => [
                    'disable_extensions' => ['capell-app/paid-suite', 'capell-app/tampered-suite'],
                ],
            ],
        ]),
    ]);

    $result = resolve(MarketplaceClient::class)->heartbeat([
        'instance_id' => 'instance-123',
        'domain' => 'example.com',
    ]);

    expect($result->alerts)->toHaveCount(1)
        ->and($result->alerts[0]->composerName)->toBe('capell-app/paid-suite')
        ->and($result->alerts[0]->siteId)->toBe('42')
        ->and($result->alerts[0]->installId)->toBe('instance-123')
        ->and($result->policy['disable_extensions'])->toBe(['capell-app/paid-suite']);
});

it('does not trust runtime disable policy without signed alerts', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'secret-value',
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/instances/heartbeat' => Http::response([
            'data' => [
                'instance_id' => 'instance-123',
                'updates' => [],
                'advisories' => [],
                'alerts' => [],
                'policy' => [
                    'disable_extensions' => ['capell-app/unsigned-suite'],
                ],
            ],
        ]),
    ]);

    $result = resolve(MarketplaceClient::class)->heartbeat([
        'instance_id' => 'instance-123',
        'domain' => 'example.com',
    ]);

    expect($result->alerts)->toBe([])
        ->and($result->policy['disable_extensions'])->toBe([]);
});

it('requests install authorization with a signed instance payload', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'secret-value',
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite/install-authorization' => Http::response([
            'data' => [
                'composer_name' => 'capell-app/seo-suite',
                'version_constraint' => '^1.2',
                'repository_url' => 'https://repo.marketplace.test/seo-suite',
                'composer_auth' => ['bearer' => 'token'],
                'expires_at' => '2026-05-08T10:00:00+00:00',
                'signed_activation' => ['activation_id' => 'act_123', 'expires_at' => '2026-05-08T10:00:00+00:00', 'signature' => 'signed'],
                'metadata' => ['source' => 'marketplace'],
            ],
        ]),
    ]);

    $authorization = resolve(MarketplaceClient::class)->createInstallAuthorization(
        slug: 'seo-suite',
        licenseKey: 'license-123',
        email: 'ben@example.com',
        installOptions: ['publish_assets' => true],
    );

    expect($authorization->composerName)->toBe('capell-app/seo-suite')
        ->and($authorization->versionConstraint)->toBe('^1.2')
        ->and($authorization->composerAuth)->toBe(['bearer' => 'token'])
        ->and($authorization->signedActivation)->toBe(['activation_id' => 'act_123', 'expires_at' => '2026-05-08T10:00:00+00:00', 'signature' => 'signed'])
        ->and($authorization->metadata)->toBe(['source' => 'marketplace']);

    Http::assertSent(function ($request): bool {
        $payload = json_decode((string) $request->body(), true, flags: JSON_THROW_ON_ERROR);
        $expectedSignature = resolve(MarketplacePayloadSigner::class)->signature($payload, 'secret-value');

        return $request->url() === 'https://marketplace.test/api/extensions/seo-suite/install-authorization'
            && $request->hasHeader('X-Capell-Instance', 'instance-123')
            && $request->hasHeader('X-Capell-Signature', $expectedSignature)
            && $payload['license_key'] === 'license-123'
            && $payload['email'] === 'ben@example.com'
            && $payload['app_url'] === config('app.url')
            && ! array_key_exists('domain', $payload)
            && $payload['install_options'] === ['publish_assets' => true]
            && $payload['signature_algorithm'] === 'hmac-sha256'
            && is_string($payload['signature_nonce'])
            && is_string($payload['signature_issued_at'])
            && $payload['signature'] === $expectedSignature
            && $payload['signature_context'] === [
                'method' => 'POST',
                'path' => '/extensions/seo-suite/install-authorization',
                'instance_id' => 'instance-123',
            ];
    });
});

it('includes account identity context in signed install authorization requests', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000126',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email' => 'ben@example.com',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite/install-authorization' => Http::response([
            'data' => [
                'composer_name' => 'capell-app/seo-suite',
                'version_constraint' => '^1.2',
            ],
        ]),
    ]);

    resolve(MarketplaceClient::class)->createInstallAuthorization(
        slug: 'seo-suite',
        licenseKey: null,
        email: 'ben@example.com',
    );

    Http::assertSent(function ($request): bool {
        $payload = json_decode((string) $request->body(), true, flags: JSON_THROW_ON_ERROR);

        return $request->url() === 'https://marketplace.test/api/extensions/seo-suite/install-authorization'
            && $payload['instance_id'] === '00000000-0000-4000-8000-000000000126'
            && $payload['account_id'] === 'acct_123'
            && ! array_key_exists('domain', $payload)
            && ! array_key_exists('claimed_domains', $payload)
            && ! array_key_exists('publicly_verified_domains', $payload)
            && ! array_key_exists('capabilities', $payload);
    });
});

it('sends queued free install telemetry without domain context', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions/install-intents' => Http::response([], 202),
    ]);

    resolve(MarketplaceClient::class)->sendFreeInstallTelemetry([
        'event_type' => 'install_intent',
        'slug' => 'free-tools',
        'composer_name' => 'capell-app/free-tools',
    ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/install-intents'
        && $request->data()['authorization_required'] === false
        && $request->data()['source'] === 'marketplace_free_install'
        && $request->data()['composer_name'] === 'capell-app/free-tools'
        && ! array_key_exists('domain', $request->data()));
});

it('requests upgrade authorization with a signed instance payload', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'secret-value',
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/upgrade-authorization' => Http::response([
            'data' => [
                'composer_name' => 'capell-app/seo-suite',
                'version_constraint' => '^2.0',
                'repository_url' => 'https://repo.marketplace.test/seo-suite',
                'composer_auth' => ['bearer' => 'upgrade-token'],
                'expires_at' => '2026-05-09T10:00:00+00:00',
                'signed_activation' => ['activation_id' => 'act_456', 'expires_at' => '2026-05-09T10:00:00+00:00', 'signature' => 'signed'],
                'metadata' => ['upgrade' => true],
            ],
        ]),
    ]);

    $authorization = resolve(MarketplaceClient::class)->createUpgradeAuthorization(
        composerName: 'capell-app/seo-suite',
        currentVersion: '1.4.0',
    );

    expect($authorization->composerName)->toBe('capell-app/seo-suite')
        ->and($authorization->versionConstraint)->toBe('^2.0')
        ->and($authorization->repositoryUrl)->toBe('https://repo.marketplace.test/seo-suite')
        ->and($authorization->composerAuth)->toBe(['bearer' => 'upgrade-token'])
        ->and($authorization->signedActivation)->toBe(['activation_id' => 'act_456', 'expires_at' => '2026-05-09T10:00:00+00:00', 'signature' => 'signed'])
        ->and($authorization->metadata)->toBe(['upgrade' => true]);

    Http::assertSent(function ($request): bool {
        $payload = json_decode((string) $request->body(), true, flags: JSON_THROW_ON_ERROR);
        $expectedSignature = resolve(MarketplacePayloadSigner::class)->signature($payload, 'secret-value');

        return $request->url() === 'https://marketplace.test/api/extensions/upgrade-authorization'
            && $request->hasHeader('X-Capell-Instance', 'instance-123')
            && $request->hasHeader('X-Capell-Signature', $expectedSignature)
            && $payload['composer_name'] === 'capell-app/seo-suite'
            && $payload['current_version'] === '1.4.0'
            && $payload['app_url'] === config('app.url')
            && ! array_key_exists('domain', $payload)
            && $payload['signature'] === $expectedSignature
            && $payload['signature_context'] === [
                'method' => 'POST',
                'path' => '/extensions/upgrade-authorization',
                'instance_id' => 'instance-123',
            ];
    });
});

it('surfaces purchase requirements from install authorization responses', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'secret-value',
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/paid-plugin/install-authorization' => Http::response([
            'message' => 'Buy this extension before installing it.',
            'data' => ['purchase_url' => 'https://marketplace.test/checkout/paid-plugin'],
        ], 402),
    ]);

    resolve(MarketplaceClient::class)->createInstallAuthorization(
        slug: 'paid-plugin',
        licenseKey: null,
        email: null,
    );
})->throws(PurchaseRequiredException::class, 'Buy this extension before installing it.');
