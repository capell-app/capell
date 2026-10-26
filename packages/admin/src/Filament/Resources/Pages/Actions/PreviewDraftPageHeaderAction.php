<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Admin\Actions\Pages\IssuePagePreviewTokenAction;
use Capell\Admin\Actions\Pages\ResolvePageJourneyLanguageAction;
use Capell\Core\Models\Page;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
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
            ->url(fn (Model $record, Component $livewire): ?string => $record instanceof Page ? $this->previewUrl($record, $livewire) : null)
            ->disabled(fn (Model $record, Component $livewire): bool => $record instanceof Page && $this->previewUrl($record, $livewire) === null)
            ->tooltip(__('capell-admin::page_journey.preview_saved'))
            ->openUrlInNewTab();
    }

    #[Override]
    public static function getDefaultName(): ?string
    {
        return 'previewDraftPage';
    }

    private function previewUrl(Page $record, Component $livewire): ?string
    {
        $languageId = $this->selectedLanguageId($livewire);
        if ($languageId !== null && (ResolvePageJourneyLanguageAction::run($record->site_id, $languageId) === null
            || ! $record->translations()->where('language_id', $languageId)->exists())) {
            return null;
        }

        return IssuePagePreviewTokenAction::run($record, languageId: $languageId);
    }

    private function selectedLanguageId(Component $livewire): ?int
    {
        $translations = data_get($livewire, 'data.translations', []);
        $activeTab = (int) data_get($livewire, 'data.activeTab', 1);
        $rows = is_array($translations) ? array_values($translations) : [];
        $languageId = $rows[max(0, $activeTab - 1)]['language_id'] ?? null;

        return is_numeric($languageId) ? (int) $languageId : null;
    }
}
