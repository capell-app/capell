<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Actions\BulkCancelScheduleBulkAction;
use Capell\Admin\Filament\Resources\Pages\Actions\BulkPublishNowBulkAction;
use Filament\Support\Icons\Heroicon;

it('configures the publish-now bulk action with confirmation copy', function (): void {
    $action = BulkPublishNowBulkAction::make();

    expect(BulkPublishNowBulkAction::getDefaultName())->toBe('bulk-publish-now')
        ->and($action->getLabel())->toBe(__('capell-admin::bulk_actions.publish_now'))
        ->and($action->getIcon())->toBe(Heroicon::OutlinedRocketLaunch)
        ->and($action->getColor())->toBe('success')
        ->and($action->getTooltip())->toBe(__('capell-admin::bulk_actions.publish_now_tooltip'))
        ->and($action->isConfirmationRequired())->toBeTrue()
        ->and($action->getModalHeading())->toBe(__('capell-admin::bulk_actions.publish_now_modal_heading'))
        ->and($action->getModalDescription())->toBe(__('capell-admin::bulk_actions.publish_now_modal_description'));
});

it('configures the cancel-schedule bulk action with confirmation copy', function (): void {
    $action = BulkCancelScheduleBulkAction::make();

    expect(BulkCancelScheduleBulkAction::getDefaultName())->toBe('bulk-cancel-schedule')
        ->and($action->getLabel())->toBe(__('capell-admin::bulk_actions.cancel_schedule'))
        ->and($action->getIcon())->toBe(Heroicon::OutlinedXCircle)
        ->and($action->getColor())->toBe('danger')
        ->and($action->getTooltip())->toBe(__('capell-admin::bulk_actions.cancel_schedule_tooltip'))
        ->and($action->isConfirmationRequired())->toBeTrue()
        ->and($action->getModalHeading())->toBe(__('capell-admin::bulk_actions.cancel_schedule_modal_heading'))
        ->and($action->getModalDescription())->toBe(__('capell-admin::bulk_actions.cancel_schedule_modal_description'));
});
