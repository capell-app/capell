<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Enums\MarketplaceAccountConnectionSessionStatus;
use Capell\Marketplace\Enums\MarketplaceCatalogueFailure;
use Capell\Marketplace\Enums\MarketplaceConnectionFailure;
use Capell\Marketplace\Filament\Actions\ConnectMarketplaceAccountAction;
use Capell\Marketplace\Filament\Actions\MarketplaceConnectionFormModel;
use Capell\Marketplace\Filament\Livewire\MarketplaceExtensionsBrowser;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Capell\Marketplace\Models\MarketplaceAccountConnectionSession;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Capell\Tests\Support\FilamentNotificationSnapshots;
use Filament\Actions\Action;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Component;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    Cache::flush();
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web'));
    test()->authenticatedUser()->givePermissionTo(Permission::findOrCreate('View:ExtensionsPage', 'web'));
    config([
        'app.url' => 'https://workspace.test',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.web_url' => 'https://marketplace.test',
        'capell-marketplace.marketplace.read_retry.retry_times' => 1,
    ]);
});

it('renders safe catalogue failures and a deliberate read retry', function (string $failure, string $reason): void {
    $sentinel = 'secret-token=remote-payload-SENTINEL';
    $catalogue = new class
    {
        public bool $unavailable = true;
    };
    Http::fake(function () use ($failure, $sentinel, $catalogue) {
        if (! $catalogue->unavailable) {
            return Http::response(['data' => [], 'links' => ['next' => null]]);
        }

        return match ($failure) {
            'connection', 'timeout' => throw new ConnectionException($sentinel),
            'verification' => throw new RuntimeException($sentinel),
            'configuration' => Http::response(['message' => $sentinel], 422),
            'malformed' => Http::response(['data' => $sentinel]),
            default => Http::response(['message' => $sentinel], 503),
        };
    });

    $component = Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertSee(__('capell-marketplace::marketplace.filters.unavailable_heading'))
        ->assertSee(MarketplaceCatalogueFailure::from($reason)->description())
        ->assertSee(__('capell-marketplace::marketplace.filters.retry'))
        ->assertDontSee($sentinel);

    $catalogue->unavailable = false;

    $retry = expectPresent($component->instance()->getTable()->getAction('retryMarketplaceCatalogue'));
    expect($retry->getLivewireClickHandler())->toBe('$refresh');

    $component->call('$refresh')
        ->assertSee(__('capell-marketplace::marketplace.filters.empty_heading'))
        ->assertDontSee(__('capell-marketplace::marketplace.filters.unavailable_heading'))
        ->assertDontSee(__('capell-marketplace::marketplace.filters.retry'));
})->with([
    'connection' => ['connection', 'connection'],
    'timeout' => ['timeout', 'connection'],
    'verification' => ['verification', 'verification'],
    'configuration' => ['configuration', 'configuration'],
    'remote server' => ['server', 'service'],
    'malformed response' => ['malformed', 'verification'],
]);

it('keeps failed account connection starts local and gives safe recovery', function (string $failure, string $reason): void {
    $sentinel = 'secret-token=connection-SENTINEL';
    if ($failure === 'configuration') {
        config(['app.url' => 'invalid-site-url']);
    }

    Http::fake(['*' => $failure === 'approval'
        ? Http::response(['data' => ['connection_session_id' => 'mcs_bad', 'approval_url' => 'https://evil.test/' . $sentinel]])
        : Http::response(['message' => $sentinel], 503)]);

    $livewire = Mockery::mock(Component::class);
    $livewire->shouldNotReceive('redirect');

    $connection = resolve(MarketplaceConnectionFormModel::class);
    $action = ConnectMarketplaceAccountAction::make($connection);
    $action->call(['livewire' => $livewire]);

    $notification = FilamentNotificationSnapshots::pull()->sole();
    expect($notification->getBody())->toBe(MarketplaceConnectionFailure::from($reason)->description())
        ->and(json_encode($notification->toArray(), JSON_THROW_ON_ERROR))->not->toContain($sentinel)
        ->and($notification->getActions())->toHaveCount(2)
        ->and($connection->connectionState())->toBe('not_connected');

    $reviewAction = $notification->getActions()[0];
    $loginAction = $notification->getActions()[1];
    Assert::assertInstanceOf(Action::class, $reviewAction);
    Assert::assertInstanceOf(Action::class, $loginAction);

    expect($reviewAction->getUrl())->toBe(MarketplacePage::getUrl())
        ->and($loginAction->getUrl())->toBe('https://marketplace.test/login');

    if ($failure === 'configuration') {
        expect(MarketplaceAccountConnectionSession::query()->count())->toBe(0);
    } else {
        expect(MarketplaceAccountConnectionSession::query()->sole()->status)->toBe(MarketplaceAccountConnectionSessionStatus::Failed);
    }
})->with([
    'unavailable service' => ['service', 'service'],
    'invalid installation URL' => ['configuration', 'configuration'],
    'untrusted approval URL' => ['approval', 'approval'],
]);

it('sends safe local failures without an unavailable or unsafe account login destination', function (string $webUrl): void {
    config([
        'capell-marketplace.marketplace.web_url' => $webUrl,
        'capell.marketplace_web_url' => '',
    ]);
    $sentinel = 'secret-token=connection-SENTINEL';
    Http::fake(['*' => Http::response(['message' => $sentinel], 503)]);

    $connection = resolve(MarketplaceConnectionFormModel::class);
    $result = $connection->startAccountConnection();

    expect($result->approvalUrl)->toBeNull()
        ->and($result->failure)->toBe(MarketplaceConnectionFailure::Service);
    $firstNotification = FilamentNotificationSnapshots::pull()->sole();

    $livewire = Mockery::mock(Component::class);
    $livewire->shouldNotReceive('redirect');
    ConnectMarketplaceAccountAction::make($connection)->call(['livewire' => $livewire]);

    foreach ([$firstNotification, FilamentNotificationSnapshots::pull()->sole()] as $notification) {
        $reviewAction = $notification->getActions()[0];
        Assert::assertInstanceOf(Action::class, $reviewAction);

        expect($notification->getTitle())->toBe(__('capell-marketplace::marketplace.marketplace.connection_failed'))
            ->and($notification->getBody())->toBe(__('capell-marketplace::marketplace.connection_failures.service'))
            ->and(json_encode($notification->toArray(), JSON_THROW_ON_ERROR))->not->toContain($sentinel)
            ->and($notification->getActions())->toHaveCount(1)
            ->and($reviewAction->getName())->toBe('reviewMarketplaceConnection')
            ->and($reviewAction->getUrl())->toBe(MarketplacePage::getUrl());
    }

    Http::assertSentCount(2);
    expect(MarketplaceAccountConnectionSession::query()->where('status', MarketplaceAccountConnectionSessionStatus::Failed)->count())->toBe(2);
})->with([
    'both web URLs empty' => [''],
    'invalid URL' => ['invalid-web-url'],
    'non-web scheme' => ['javascript:alert(1)'],
    'embedded credentials' => ['https://secret-token@marketplace.test'],
    'query string' => ['https://marketplace.test?next=https://evil.test'],
    'fragment' => ['https://marketplace.test#account'],
]);

it('starts a deliberate fresh attempt on retry and redirects only to validated approval', function (): void {
    Http::fakeSequence()
        ->push(['message' => 'unavailable'], 503)
        ->push(['data' => ['connection_session_id' => 'mcs_retry', 'approval_url' => 'https://marketplace.test/connect/mcs_retry']], 201);
    $connection = resolve(MarketplaceConnectionFormModel::class);
    $failed = $connection->startAccountConnection();
    expect($failed->approvalUrl)->toBeNull();

    $livewire = Mockery::mock(Component::class);
    $livewire->shouldReceive('redirect')->once()->with('https://marketplace.test/connect/mcs_retry');
    ConnectMarketplaceAccountAction::make($connection)->call(['livewire' => $livewire]);

    $sessions = MarketplaceAccountConnectionSession::query()->orderBy('id')->get();
    expect($sessions)->toHaveCount(2);
    $failedSession = expectPresent($sessions->get(0));
    $pendingSession = expectPresent($sessions->get(1));

    expect($failedSession->status)->toBe(MarketplaceAccountConnectionSessionStatus::Failed)
        ->and($pendingSession->status)->toBe(MarketplaceAccountConnectionSessionStatus::Pending)
        ->and($failedSession->state_hash)->not->toBe($pendingSession->state_hash)
        ->and($failedSession->code_verifier_hash)->not->toBe($pendingSession->code_verifier_hash)
        ->and($connection->hasAccountLinkedInstance())->toBeFalse();
});

it('denies connection starts without management permission', function (): void {
    test()->authenticatedUser()->revokePermissionTo(ExtensionsPage::MANAGE_PERMISSION);
    Http::fake();
    $livewire = Mockery::mock(Component::class);
    $livewire->shouldNotReceive('redirect');

    $action = ConnectMarketplaceAccountAction::make(resolve(MarketplaceConnectionFormModel::class));

    expect(fn (): mixed => $action->call(['livewire' => $livewire]))->toThrow(HttpException::class);
    expect(fn () => resolve(MarketplaceConnectionFormModel::class)->startAccountConnection())->toThrow(HttpException::class);
    Http::assertNothingSent();
    expect(MarketplaceAccountConnectionSession::query()->count())->toBe(0);
});

it('keeps a missing catalogue connection distinct from zero matches without contacting a remote service', function (?string $baseUrl): void {
    config(['capell-marketplace.marketplace.base_url' => $baseUrl]);
    Http::fake();

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertSee(__('capell-marketplace::marketplace.catalogue_failures.configuration'))
        ->assertDontSee(__('capell-marketplace::marketplace.filters.empty_heading'));

    Http::assertNothingSent();
})->with([
    'missing URL' => [null],
    'empty URL' => [''],
    'invalid URL' => ['not-a-url'],
    'non-web scheme' => ['ftp://marketplace.test/api'],
    'embedded credentials' => ['https://secret-token@marketplace.test/api'],
]);

it('allows a view-only catalogue reader to retry without exposing management recovery', function (): void {
    test()->authenticatedUser()->revokePermissionTo(ExtensionsPage::MANAGE_PERMISSION);
    Http::fake(['*' => Http::response(['message' => 'remote secret'], 503)]);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->assertSee(__('capell-marketplace::marketplace.filters.retry'))
        ->assertDontSee(__('capell-marketplace::marketplace.connection_failures.review_connection'))
        ->assertDontSee('remote secret');
});
