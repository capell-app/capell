<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Enums\MarketplaceInstallFlowSessionStatus;
use Capell\Marketplace\Models\MarketplaceInstallFlowSession;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    config([
        'app.url' => 'http://capell-ruby.test',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api/v1',
        'capell-marketplace.marketplace.webhook_secret' => 'fallback-secret',
    ]);

    test()->actingAsAdmin();
    Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web');
    auth()->user()?->givePermissionTo(ExtensionsPage::MANAGE_PERMISSION);
});

it('shows a persistent success notification with queued extensions and support reference', function (): void {
    Bus::fake();

    MarketplaceInstallFlowSession::query()->create([
        'remote_flow_id' => 'mif_notify',
        'contract_version' => 2,
        'status' => MarketplaceInstallFlowSessionStatus::Redirected,
        'selected_extensions' => [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-marketplace/premium-seo',
                'name' => 'Premium SEO',
                'kind' => 'tool',
                'price_cents' => 4900,
            ],
        ],
        'quoted_extensions' => [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-marketplace/premium-seo',
                'name' => 'Premium SEO',
                'kind' => 'tool',
                'price_cents' => 4900,
            ],
        ],
        'quoted_price_cents' => 4900,
        'quoted_currency' => 'usd',
        'state_hash' => hash('sha256', 'state_123'),
        'code_verifier_hash' => hash('sha256', 'verifier_123'),
        'code_verifier_encrypted' => 'verifier_123',
        'approval_url' => 'https://marketplace.test/marketplace/install-flows/mif_notify',
        'return_url' => 'http://capell-ruby.test/admin/marketplace/install-flow/callback',
        'expires_at' => now()->addMinutes(10),
        'redirected_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/v1/marketplace/install-flows/exchange' => Http::response([
            'data' => [
                'flow_id' => 'mif_notify',
                'contract_version' => 2,
                'can_install' => true,
                'account' => [
                    'account_id' => 'user_123',
                    'account_name' => 'Ben Johnson',
                    'account_email' => 'ben@example.com',
                    'account_email_verified_at' => now()->toIso8601String(),
                ],
                'instance' => [
                    'instance_id' => '00000000-0000-4000-8000-000000000123',
                    'signing_secret' => 'new-secret',
                ],
                'quote' => [
                    'currency' => 'usd',
                    'price_cents' => 4900,
                    'extensions' => [
                        [
                            'slug' => 'premium-seo',
                            'composer_name' => 'capell-marketplace/premium-seo',
                            'name' => 'Premium SEO',
                            'kind' => 'tool',
                            'price_cents' => 4900,
                        ],
                    ],
                ],
                'entitlements' => [
                    'capell-marketplace/premium-seo' => 123,
                ],
                'eligibility' => [
                    [
                        'composer_name' => 'capell-marketplace/premium-seo',
                        'state' => 'authorized',
                        'can_install' => true,
                    ],
                ],
            ],
        ]),
        'https://marketplace.test/api/v1/extensions/premium-seo' => Http::response([
            'data' => [
                'slug' => 'premium-seo',
                'name' => 'Premium SEO',
                'composer_name' => 'capell-marketplace/premium-seo',
                'kind' => 'tool',
                'price_cents' => 4900,
                'is_paid' => true,
                'latest_version' => '1.2.3',
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                    'can_update' => true,
                    'can_run_existing' => true,
                ],
            ],
        ]),
        'https://marketplace.test/api/v1/extensions/premium-seo/install-authorization' => Http::response([
            'data' => [
                'composer_name' => 'capell-marketplace/premium-seo',
                'version_constraint' => '^1.2.3',
                'repository_url' => null,
                'composer_auth' => null,
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
                'metadata' => [
                    'entitlement_id' => 123,
                ],
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                    'can_update' => true,
                    'can_run_existing' => true,
                ],
            ],
        ]),
        'https://marketplace.test/api/v1/extensions/install-intents' => Http::response(['data' => ['recorded' => true]], 201),
    ]);

    $this->get('/admin/marketplace/install-flow/callback?flow_id=mif_notify&code=code_123&state=state_123')
        ->assertRedirect('/admin/extensions')
        ->assertSessionHas('capell-marketplace.open-marketplace', true)
        ->assertSessionHas('capell-marketplace.install-flow-completed', true)
        ->assertSessionHas('capell-marketplace.install-flow-support-reference', 'mif_notify')
        ->assertSessionHas('capell-marketplace.affected-composer-names', ['capell-marketplace/premium-seo']);

    Notification::assertNotified(
        Notification::make()
            ->title((string) __('capell-marketplace::marketplace.install_flow.completed_title'))
            ->body(trans_choice('capell-marketplace::marketplace.install_flow.completed_body', 1, [
                'count' => 1,
                'extensions' => 'Premium SEO',
                'reference' => 'mif_notify',
            ]))
            ->success()
            ->persistent(),
    );
});

it('shows a safe failed notification with a support reference when callback data is incomplete', function (): void {
    $this->get('/admin/marketplace/install-flow/callback?flow_id=mif_missing')
        ->assertRedirect('/admin/extensions')
        ->assertSessionHas('capell-marketplace.open-marketplace', true);

    Notification::assertNotified(
        Notification::make()
            ->title((string) __('capell-marketplace::marketplace.install_flow.failed_title'))
            ->body((string) __('capell-marketplace::marketplace.install_flow.failed_body', [
                'reference' => 'mif_missing',
            ]))
            ->danger()
            ->persistent(),
    );
});
