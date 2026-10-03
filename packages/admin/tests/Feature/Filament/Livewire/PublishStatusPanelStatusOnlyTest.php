<?php

declare(strict_types=1);

use Capell\Admin\Filament\Livewire\PublishStatusPanel;
use Capell\Admin\Tests\Fixtures\Publishing\StatusOnlyRecord;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    Schema::create('status_only_records', function (Blueprint $table): void {
        $table->id();
        $table->boolean('status')->default(true);
    });
});

function statusOnlyPanel(StatusOnlyRecord $record): Testable
{
    return Livewire::test(PublishStatusPanel::class, [
        'recordClass' => StatusOnlyRecord::class,
        'recordId' => $record->getKey(),
    ]);
}

it('renders a status-only panel and hides every publish action', function (): void {
    test()->actingAsAdmin();
    $record = StatusOnlyRecord::query()->create(['status' => true]);

    statusOnlyPanel($record)
        ->assertActionVisible('toggleStatus')
        ->assertActionHidden('publishNow')
        ->assertActionHidden('schedulePublish')
        ->assertActionHidden('setExpiry')
        ->assertActionHidden('revertToDraft')
        ->assertActionHidden('unpublish')
        ->assertActionHidden('cancelScheduledUnpublish');
});

it('toggles Active/Inactive on a status-only record', function (): void {
    test()->actingAsAdmin();
    $record = StatusOnlyRecord::query()->create(['status' => true]);

    statusOnlyPanel($record)->callAction('toggleStatus');

    expect($record->fresh()?->isEnabled())->toBeFalse();
});

it('shows an Active badge instead of a publish-status badge', function (): void {
    test()->actingAsAdmin();
    $record = StatusOnlyRecord::query()->create(['status' => true]);

    statusOnlyPanel($record)
        ->assertSee(__('capell-admin::publish_panel.status_active'))
        ->assertDontSee(__('capell-admin::publish_panel.not_published'));
});

it('ignores a saved page with the same identifier as a non-page resource', function (): void {
    test()->actingAsAdmin();
    $record = StatusOnlyRecord::query()->create(['status' => true]);
    $component = statusOnlyPanel($record)->instance();
    $view = $component->viewData;
    $record->update(['status' => false]);

    $component->refreshAfterPageSaved((int) $record->getKey());

    expect($component->viewData)->toBe($view)
        ->and($component->viewData->isEnabled())->toBeTrue();
});
