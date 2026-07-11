<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Admin\Actions\Pages\IssuePagePreviewTokenAction;
use Capell\Core\Models\Page;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Override;

/**
 * "Preview draft" header action — visible only when the page is currently in
 * draft state (isPending()). Opens a signed preview URL in a new tab.
 *
 * The signed preview route is Page-specific, so this action only applies to
 * core Page records. Resources that inherit EditPage for a different model
 * (e.g. the Blog Article resource) pass that model in as $record; the
 * instanceof guard hides the action there instead of throwing a TypeError.
 */
class PreviewDraftPageHeaderAction extends Action
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::button.preview_draft'))
            ->icon(Heroicon::OutlinedEye)
            ->color('warning')
            ->visible(fn (Model $record): bool => $record instanceof Page && $record->isPending() && Route::has('capell.admin.preview-page'))
            ->url(fn (Model $record): ?string => $record instanceof Page ? IssuePagePreviewTokenAction::run($record) : null)
            ->openUrlInNewTab();
    }

    public static function getDefaultName(): ?string
    {
        return 'previewDraftPage';
    }
}
