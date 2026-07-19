<?php

declare(strict_types=1);

use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Filament\Actions\MarketplaceConnectionFormModel;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class);

it('restricts marketplace connection details to the configured global superadmin role', function (): void {
    Role::findOrCreate('admin');
    Role::findOrCreate('super_admin');
    $manager = test()->createUserWithRole('admin');
    $superAdmin = test()->createUserWithRole('super_admin');

    $this->actingAs($manager);
    expect(resolve(MarketplaceConnectionFormModel::class)->canViewConnectionDetails())->toBeFalse();

    $this->actingAs($superAdmin);
    expect(resolve(MarketplaceConnectionFormModel::class)->canViewConnectionDetails())->toBeTrue();
});

it('reports marketplace connection state from configuration and connected account records', function (): void {
    $formModel = resolve(MarketplaceConnectionFormModel::class);

    config(['capell-marketplace.marketplace.base_url' => null]);

    expect($formModel->connectionState())->toBe('needs_configuration')
        ->and($formModel->canStartRegistration())->toBeFalse()
        ->and($formModel->connectionLanguagePath('label'))->toBe('capell-marketplace::marketplace.marketplace.status.needs_configuration.label');

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    expect(resolve(MarketplaceConnectionFormModel::class)->connectionState())->toBe('not_connected')
        ->and(resolve(MarketplaceConnectionFormModel::class)->canStartRegistration())->toBeTrue()
        ->and(resolve(MarketplaceConnectionFormModel::class)->connectionLanguagePath('title'))->toBe('capell-marketplace::marketplace.marketplace.status.not_connected.title');

    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'secret-value',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email' => 'ben@example.com',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);
    resolve(MarketplaceInstanceResolver::class)->forget();

    $connectedFormModel = resolve(MarketplaceConnectionFormModel::class);

    expect($connectedFormModel->connectionState())->toBe('connected')
        ->and($connectedFormModel->hasAccountLinkedInstance())->toBeTrue()
        ->and($connectedFormModel->hasVerifiedDomains())->toBeFalse()
        ->and($connectedFormModel->domainStatuses())->toBe([])
        ->and($connectedFormModel->connectionLanguagePath('label'))->toBe('capell-marketplace::marketplace.marketplace.status.connected.label');
});

it('runs marketplace heartbeat from the connection form model', function (): void {
    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.webhook_url' => 'https://example.test/capell/marketplace/webhook',
    ]);

    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-heartbeat',
        'signing_secret_encrypted' => 'heartbeat-secret',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'last_heartbeat_at' => now()->subHour(),
    ]);

    Http::fake([
        'https://marketplace.test/api/instances/heartbeat' => Http::response([
            'data' => [
                'instance_id' => 'instance-heartbeat',
                'updates' => [],
                'advisories' => [],
            ],
        ]),
    ]);

    resolve(MarketplaceConnectionFormModel::class)->runHeartbeat();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/instances/heartbeat'
        && $request->data()['event_type'] === 'extension_health_report'
        && $request->data()['source'] === 'heartbeat'
        && $request->data()['instance_id'] === 'instance-heartbeat');
});

it('reports marketplace heartbeat failures from the connection form model', function (): void {
    config([
        'capell-marketplace.marketplace.base_url' => null,
        'capell-marketplace.marketplace.troubleshooting_url' => null,
    ]);

    resolve(MarketplaceConnectionFormModel::class)->runHeartbeat();

    Notification::assertNotified(
        Notification::make('marketplace-error')
            ->title((string) __('capell-marketplace::marketplace.install.heartbeat_failed'))
            ->body((string) __('capell-marketplace::marketplace.install.heartbeat_failed_body', [
                'reason' => 'The marketplace URL is not configured. Set CAPELL_MARKETPLACE_URL to the Capell marketplace API URL.',
            ]))
            ->danger()
            ->persistent(),
    );
});
