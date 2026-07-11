<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\ResolvePublishPanelViewAction;
use Capell\Admin\Tests\Fixtures\Publishing\StatusOnlyRecord;

it('builds status-only view data for a Statusable, non-Publishable record', function (): void {
    $record = tap(new StatusOnlyRecord, fn (StatusOnlyRecord $record): bool => $record->status = true);

    $view = ResolvePublishPanelViewAction::run($record);

    expect($view->isPublishable())->toBeFalse()
        ->and($view->isStatusable())->toBeTrue()
        ->and($view->isEnabled())->toBeTrue()
        ->and($view->publishedAt)->toBeNull()
        ->and($view->goesLiveAt)->toBeNull()
        ->and($view->expiresAt)->toBeNull();
});

it('reflects a disabled Statusable-only record', function (): void {
    $record = tap(new StatusOnlyRecord, fn (StatusOnlyRecord $record): bool => $record->status = false);

    expect(ResolvePublishPanelViewAction::run($record)->isEnabled())->toBeFalse();
});
