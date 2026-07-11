<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Enums\MarketplaceAccountConnectionSessionStatus;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Models\MarketplaceAccountConnectionSession;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('completes account connection from the marketplace callback', function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1']);

    test()->actingAsAdmin();
    Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web');
    auth()->user()?->givePermissionTo(ExtensionsPage::MANAGE_PERMISSION);

    MarketplaceAccountConnectionSession::query()->create([
        'connection_session_id' => 'mcs_123',
        'state_hash' => hash('sha256', 'state_123'),
        'code_verifier_hash' => hash('sha256', 'verifier_123'),
        'code_verifier_encrypted' => 'verifier_123',
        'claimed_domain' => 'capell-ruby.test',
        'app_url' => 'http://capell-ruby.test',
        'callback_url' => 'http://capell-ruby.test/admin/marketplace/connection/callback',
        'status' => MarketplaceAccountConnectionSessionStatus::Pending,
        'expires_at' => now()->addMinutes(10),
    ]);

    Http::fake([
        'https://capell.test/api/v1/marketplace/connections/exchange' => Http::response([
            'data' => [
                'instance_id' => '00000000-0000-4000-8000-000000000123',
                'signing_secret' => 'secret-value',
                'account_id' => 'acct_123',
                'account_name' => 'Ben Johnson',
                'account_email' => 'ben@example.com',
                'account_email_verified_at' => now()->toIso8601String(),
            ],
        ]),
    ]);

    $this->get('/admin/marketplace/connection/callback?connection_session_id=mcs_123&code=code_123&state=state_123')
        ->assertRedirect('/admin/extensions')
        ->assertSessionHas('capell-marketplace.open-marketplace', true);

    $instance = expectPresent(MarketplaceInstance::query()->firstWhere('instance_id', '00000000-0000-4000-8000-000000000123'));

    expect($instance)->toBeInstanceOf(MarketplaceInstance::class)
        ->and($instance->connection_mode)->toBe(MarketplaceConnectionMode::AccountLinked)
        ->and($instance->account_email)->toBe('ben@example.com');
});

it('shows a safe callback failure message without exposing internal session details', function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1']);

    test()->actingAsAdmin();
    Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web');
    auth()->user()?->givePermissionTo(ExtensionsPage::MANAGE_PERMISSION);

    $this->get('/admin/marketplace/connection/callback?connection_session_id=missing&code=code_123&state=state_123')
        ->assertRedirect('/admin/extensions');

    Notification::assertNotified(
        Notification::make()
            ->title((string) __('capell-marketplace::marketplace.marketplace.account_connection_failed'))
            ->body((string) __('capell-marketplace::marketplace.marketplace.account_connection_failed_body'))
            ->danger()
            ->persistent(),
    );
});
