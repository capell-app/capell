<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Actions\ClearMarketplaceInstallOperationsAction;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallFlowSessionStatus;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Filament\Widgets\MarketplaceInstallOperationsFilamentWidget;
use Capell\Marketplace\Filament\Widgets\MarketplacePackageOperationsAlertFilamentWidget;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallFlowSession;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();

    Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web');
    test()->authenticatedUser()->givePermissionTo(ExtensionsPage::MANAGE_PERMISSION);
});

it('summarises active and attention package operations for the main dashboard', function (): void {
    foreach (range(1, 11) as $operationNumber) {
        marketplaceWidgetAttempt([
            'composer_name' => 'capell-app/queued-suite-' . $operationNumber,
            'extension_slug' => 'queued-suite-' . $operationNumber,
            'status' => MarketplaceInstallIntentStatus::Queued,
        ]);
    }

    marketplaceWidgetAttempt([
        'composer_name' => 'capell-app/deployment-failed-suite',
        'extension_slug' => 'deployment-failed-suite',
        'status' => MarketplaceInstallIntentStatus::Succeeded,
        'deployment' => [
            'status' => 'failed',
            'failure_reason' => 'Deployment connection is disconnected.',
        ],
    ]);

    $widget = new MarketplacePackageOperationsAlertFilamentWidget;

    expect($widget->activeCount())->toBe(11)
        ->and($widget->attentionCount())->toBe(1);
});

it('dismisses package operation attention alerts without deleting history', function (): void {
    $attempt = marketplaceWidgetAttempt([
        'composer_name' => 'capell-app/blog',
        'extension_slug' => 'blog',
        'extension_name' => 'Blog',
        'status' => MarketplaceInstallIntentStatus::Failed,
        'failure_stage' => 'lifecycle',
        'failure_type' => 'install_command',
        'failure_reason' => 'Package capell-app/blog must add a lifecycle Action.',
        'completed_at' => now(),
        'resolved_at' => null,
    ]);

    Livewire::test(MarketplacePackageOperationsAlertFilamentWidget::class)
        ->assertSee('Blog')
        ->assertSee(__('capell-marketplace::marketplace.operations.dismiss'))
        ->call('dismiss', $attempt->id)
        ->assertDontSee('Blog');

    expect($attempt->refresh()->resolved_at)->not->toBeNull()
        ->and(MarketplaceInstallAttempt::query()->whereKey($attempt->id)->exists())->toBeTrue();
});

it('filters attention operations and exposes retry affordances for recoverable alerts', function (): void {
    Notification::fake();
    Queue::fake();

    $failedAttempt = marketplaceWidgetAttempt([
        'composer_name' => 'capell-app/failed-suite',
        'extension_slug' => 'failed-suite',
        'status' => MarketplaceInstallIntentStatus::Failed,
        'completed_at' => now(),
    ]);
    $cancelledAfterComposer = marketplaceWidgetAttempt([
        'composer_name' => 'capell-app/cancel-after-composer',
        'extension_slug' => 'cancel-after-composer',
        'status' => MarketplaceInstallIntentStatus::Cancelled,
        'failure_type' => MarketplaceInstallFailureType::CancelledAfterComposer->value,
        'cancelled_at' => now(),
    ]);
    $cancelledBeforeComposer = marketplaceWidgetAttempt([
        'composer_name' => 'capell-app/cancel-before-composer',
        'extension_slug' => 'cancel-before-composer',
        'status' => MarketplaceInstallIntentStatus::Cancelled,
        'failure_type' => MarketplaceInstallFailureType::Unknown->value,
        'cancelled_at' => now(),
    ]);
    $resolvedAttempt = marketplaceWidgetAttempt([
        'composer_name' => 'capell-app/resolved-suite',
        'extension_slug' => 'resolved-suite',
        'status' => MarketplaceInstallIntentStatus::Failed,
        'completed_at' => now(),
        'resolved_at' => now(),
    ]);

    $widget = new MarketplacePackageOperationsAlertFilamentWidget;

    expect($widget->attentionOperations()->pluck('composer_name')->sort()->values()->all())
        ->toBe([
            'capell-app/cancel-after-composer',
            'capell-app/cancel-before-composer',
            'capell-app/failed-suite',
        ])
        ->and($widget->canRetry($failedAttempt))->toBeTrue()
        ->and($widget->canRetry($cancelledAfterComposer))->toBeTrue()
        ->and($widget->canRetry($cancelledBeforeComposer))->toBeFalse()
        ->and($widget->operationUrl($failedAttempt))->toContain('operation=' . $failedAttempt->getKey());

    $widget->retry((int) $cancelledAfterComposer->getKey());
    $widget->retry((int) $cancelledBeforeComposer->getKey());
    $widget->retry((int) $resolvedAttempt->getKey() + 1000);

    expect(MarketplaceInstallAttempt::query()->where('retry_of_id', $cancelledAfterComposer->getKey())->exists())->toBeTrue()
        ->and(MarketplaceInstallAttempt::query()->where('retry_of_id', $cancelledBeforeComposer->getKey())->exists())->toBeFalse();
});

it('does not mutate dashboard package operation alerts for users without manage permission', function (): void {
    Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web');
    test()->actingAsUser();

    $attempt = marketplaceWidgetAttempt([
        'composer_name' => 'capell-app/blog',
        'extension_slug' => 'blog',
        'extension_name' => 'Blog',
        'status' => MarketplaceInstallIntentStatus::Failed,
        'completed_at' => now(),
        'resolved_at' => null,
    ]);

    $widget = new MarketplacePackageOperationsAlertFilamentWidget;

    expect($widget->canRetry($attempt))->toBeFalse();

    $widget->dismiss((int) $attempt->getKey());
    $widget->retry((int) $attempt->getKey());

    expect($attempt->refresh()->resolved_at)->toBeNull()
        ->and(MarketplaceInstallAttempt::query()->where('retry_of_id', $attempt->getKey())->exists())->toBeFalse();
});

it('lists deployment handoff failures even after local install succeeds', function (): void {
    marketplaceWidgetAttempt([
        'composer_name' => 'capell-app/deployment-failed-suite',
        'extension_slug' => 'deployment-failed-suite',
        'status' => MarketplaceInstallIntentStatus::Succeeded,
        'deployment' => [
            'status' => 'failed',
            'failure_reason' => 'Deployment connection is disconnected.',
        ],
    ]);

    $operations = (new MarketplaceInstallOperationsFilamentWidget)->operations();
    /** @var MarketplaceInstallAttempt|null $firstOperation */
    $firstOperation = $operations->first();

    expect($operations)->toHaveCount(1)
        ->and($firstOperation?->composer_name)->toBe('capell-app/deployment-failed-suite');
});

it('cancels queued package operations from the install operations modal widget', function (): void {
    $attempt = marketplaceWidgetAttempt(['status' => MarketplaceInstallIntentStatus::Queued]);

    $widget = new MarketplaceInstallOperationsFilamentWidget;

    expect($widget->canCancel($attempt))->toBeTrue();

    $widget->cancel((int) $attempt->getKey());

    expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
        ->and($attempt->cancelled_at)->not->toBeNull();
});

it('does not mutate install operation widget state for users without manage permission', function (): void {
    test()->actingAsUser();

    $attempt = marketplaceWidgetAttempt(['status' => MarketplaceInstallIntentStatus::Queued]);
    $resumableSession = MarketplaceInstallFlowSession::query()->create([
        'remote_flow_id' => 'mif_resumable',
        'status' => MarketplaceInstallFlowSessionStatus::Failed,
        'selected_extensions' => [],
        'quoted_extensions' => [],
        'state_hash' => hash('sha256', 'resumable'),
        'code_verifier_hash' => hash('sha256', 'resumable-verifier'),
        'code_verifier_encrypted' => 'resumable-verifier',
    ]);
    $expirableSession = MarketplaceInstallFlowSession::query()->create([
        'remote_flow_id' => 'mif_expirable',
        'status' => MarketplaceInstallFlowSessionStatus::Redirected,
        'selected_extensions' => [],
        'quoted_extensions' => [],
        'state_hash' => hash('sha256', 'expirable'),
        'code_verifier_hash' => hash('sha256', 'expirable-verifier'),
        'code_verifier_encrypted' => 'expirable-verifier',
    ]);

    $widget = new MarketplaceInstallOperationsFilamentWidget;

    expect($widget->canCancel($attempt))->toBeFalse()
        ->and($widget->canResumeFlowSession($resumableSession))->toBeFalse()
        ->and($widget->canExpireFlowSession($expirableSession))->toBeFalse();

    $widget->cancel((int) $attempt->getKey());
    $widget->resumeFlowSession((int) $resumableSession->getKey());
    $widget->expireFlowSession((int) $expirableSession->getKey());

    expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($resumableSession->refresh()->status)->toBe(MarketplaceInstallFlowSessionStatus::Failed)
        ->and($expirableSession->refresh()->status)->toBe(MarketplaceInstallFlowSessionStatus::Redirected);
});

it('renders the install operations action content with the operations widget', function (): void {
    marketplaceWidgetAttempt();

    $content = view('capell-marketplace::filament.actions.install-operations')->render();

    expect($content)->toContain('capell-app/seo-suite');
});

it('requires confirmation before cancelling package operations from the widget', function (): void {
    marketplaceWidgetAttempt(['status' => MarketplaceInstallIntentStatus::Queued]);

    Livewire::test(MarketplaceInstallOperationsFilamentWidget::class)
        ->assertSeeHtml('wire:confirm')
        ->assertSeeHtml(e(__('capell-marketplace::marketplace.operations.cancel_confirm')));
});

it('does not show cancel for completed package operations', function (): void {
    $attempt = marketplaceWidgetAttempt(['status' => MarketplaceInstallIntentStatus::Failed]);

    expect((new MarketplaceInstallOperationsFilamentWidget)->canCancel($attempt))->toBeFalse();
});

it('keeps failed package attempts visible with expandable logs', function (): void {
    marketplaceWidgetAttempt([
        'composer_name' => 'capell-app/filament-peek',
        'extension_slug' => 'filament-peek',
        'extension_name' => 'Filament Peek',
        'status' => MarketplaceInstallIntentStatus::Failed,
        'failure_reason' => 'PHP FPM cannot run Composer.',
        'output_excerpt' => 'Usage: php-fpm [-n] [-e]',
        'error_excerpt' => 'Composer did not run.',
        'completed_at' => now(),
        'resolved_at' => null,
    ]);

    Livewire::test(MarketplaceInstallOperationsFilamentWidget::class)
        ->call('setOperationsTab', 'failed')
        ->assertSee(__('capell-marketplace::marketplace.operations.tab_failed'))
        ->assertSee('Filament Peek')
        ->assertSee(__('capell-marketplace::marketplace.operations.view_logs'))
        ->call('toggleOperationLog', MarketplaceInstallAttempt::query()->where('composer_name', 'capell-app/filament-peek')->value('id'))
        ->assertSee(__('capell-marketplace::marketplace.operations.log_failure_reason'))
        ->assertSee('PHP FPM cannot run Composer.')
        ->assertSee('Usage: php-fpm [-n] [-e]')
        ->assertSee('Composer did not run.');
});

it('renders interrupted hosted install flow sessions in package operations', function (): void {
    MarketplaceInstallFlowSession::query()->create([
        'remote_flow_id' => 'mif_recover',
        'status' => MarketplaceInstallFlowSessionStatus::Failed,
        'selected_extensions' => [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-app/premium-seo',
            ],
        ],
        'quoted_extensions' => [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-app/premium-seo',
                'price_cents' => 4900,
            ],
        ],
        'quoted_price_cents' => 4900,
        'quoted_currency' => 'usd',
        'last_exchange_payload' => [
            'account' => [
                'account_email' => 'buyer@example.com',
            ],
        ],
        'remote_entitlement_ids' => [
            'capell-app/premium-seo' => 123,
        ],
        'state_hash' => hash('sha256', 'state_123'),
        'code_verifier_hash' => hash('sha256', 'verifier_123'),
        'code_verifier_encrypted' => 'verifier_123',
        'last_error' => 'purchase_required',
        'expires_at' => now()->addMinutes(10),
    ]);

    Livewire::test(MarketplaceInstallOperationsFilamentWidget::class)
        ->assertSee(__('capell-marketplace::marketplace.operations.flow_sessions_heading'))
        ->assertSee('mif_recover')
        ->assertSee(__('capell-marketplace::marketplace.operations.flow_status.failed'))
        ->assertSee(__('capell-marketplace::marketplace.operations.flow_support_reference'))
        ->assertSee(__('capell-marketplace::marketplace.operations.flow_account_email'))
        ->assertSee(__('capell-marketplace::marketplace.operations.flow_last_safe_actions.resume'))
        ->assertSee('buyer@example.com')
        ->assertSee('capell-app/premium-seo')
        ->assertSee('123')
        ->assertSee('purchase_required');
});

it('expires hosted install flow sessions without touching package attempts', function (): void {
    $session = MarketplaceInstallFlowSession::query()->create([
        'remote_flow_id' => 'mif_expire',
        'status' => MarketplaceInstallFlowSessionStatus::Returned,
        'selected_extensions' => [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-app/premium-seo',
            ],
        ],
        'state_hash' => hash('sha256', 'state_123'),
        'code_verifier_hash' => hash('sha256', 'verifier_123'),
        'code_verifier_encrypted' => 'verifier_123',
        'expires_at' => now()->subMinute(),
    ]);
    $attempt = marketplaceWidgetAttempt(['status' => MarketplaceInstallIntentStatus::Queued]);

    Livewire::test(MarketplaceInstallOperationsFilamentWidget::class)
        ->call('expireFlowSession', $session->id);

    expect($session->refresh()->status)->toBe(MarketplaceInstallFlowSessionStatus::Expired)
        ->and($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Queued);
});

it('clears resolved package operations without clearing active installs', function (): void {
    $failedAttempt = marketplaceWidgetAttempt(['status' => MarketplaceInstallIntentStatus::Failed]);
    $queuedAttempt = marketplaceWidgetAttempt([
        'composer_name' => 'capell-app/queued-suite',
        'extension_slug' => 'queued-suite',
        'status' => MarketplaceInstallIntentStatus::Queued,
    ]);

    expect((new ClearMarketplaceInstallOperationsAction)->count())->toBe(1);

    expect(ClearMarketplaceInstallOperationsAction::run())->toBe(1);

    expect($failedAttempt->refresh()->resolved_at)->not->toBeNull()
        ->and($queuedAttempt->refresh()->resolved_at)->toBeNull()
        ->and((new MarketplaceInstallOperationsFilamentWidget)->operations()->pluck('composer_name')->all())
        ->toBe(['capell-app/queued-suite']);
});

function marketplaceWidgetAttempt(array $overrides = []): MarketplaceInstallAttempt
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
