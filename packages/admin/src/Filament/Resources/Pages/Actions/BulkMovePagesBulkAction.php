<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Admin\Actions\Pages\BuildPageMoveTargetOptionsAction;
use Capell\Admin\Actions\Pages\BulkMovePagesAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Override;

class BulkMovePagesBulkAction extends BulkAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        /** @var class-string<Page> $pageModel */
        $pageModel = Page::class;

        $this->label(__('capell-admin::bulk_actions.move_pages'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->tooltip(__('capell-admin::bulk_actions.move_pages_tooltip'))
            ->schema([
                Select::make('parent_id')
                    ->label(__('capell-admin::bulk_actions.move_pages_parent'))
                    ->searchable()
                    ->required()
                    ->helperText(__('capell-admin::page_moves.target_help'))
                    ->getSearchResultsUsing(fn (string $search, HasTable $livewire): array => BuildPageMoveTargetOptionsAction::run($livewire->getSelectedTableRecords(), auth()->user(), $search))
                    ->getOptionLabelUsing(function (mixed $value) use ($pageModel): ?string {
                        $page = SiteAccess::current()->query($pageModel)->find($value);

                        return $page instanceof Page && Gate::allows('view', $page) ? $page->name : null;
                    }),
                Toggle::make('add_redirects')
                    ->label(__('capell-admin::bulk_actions.move_pages_add_redirects'))
                    ->default(false),
            ])
            ->action(function (Collection $records, array $data) use ($pageModel): void {
                /** @var (Page&Pageable)|null $newParent */
                $newParent = SiteAccess::current()->query($pageModel)->find($data['parent_id']);

                if ($newParent === null) {
                    Notification::make()
                        ->title(__('capell-admin::bulk_actions.move_pages_parent_not_found'))
                        ->danger()
                        ->send();

                    return;
                }

                /** @var User $actor */
                $actor = auth()->user();

                $result = BulkMovePagesAction::run($records, $newParent, $actor, (bool) ($data['add_redirects'] ?? false));

                if ($result['failed_at'] !== null) {
                    $failedRecord = $records->find($result['failed_at']['id']);
                    Notification::make()
                        ->title(__('capell-admin::bulk_actions.move_pages_failed'))
                        ->body(__('capell-admin::bulk_actions.move_pages_failed_body', [
                            'page' => $failedRecord instanceof Page && Gate::allows('view', $failedRecord) ? e($failedRecord->name) : __('capell-admin::page_moves.batch'),
                            'reason' => __('capell-admin::page_moves.failed_rollback'),
                        ]))
                        ->danger()
                        ->send();

                    return;
                }

                if ($result['moved'] === 0) {
                    Notification::make()
                        ->title(__('capell-admin::bulk_actions.move_pages_none_moved'))
                        ->body($this->outcomeDetails($records, $result['skipped_pages'], $newParent, false))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('capell-admin::bulk_actions.move_pages_done', [
                        'moved' => $result['moved'],
                        'skipped' => $result['skipped'],
                    ]))
                    ->body($this->outcomeDetails($records, $result['skipped_pages'], $newParent, true))
                    ->success()
                    ->send();
            });
    }

    #[Override]
    public static function getDefaultName(): ?string
    {
        return 'bulk-move-pages';
    }

    /**
     * @param  Collection<int, Page>  $records
     * @param  list<array{id: int, name: string, reason: string}>  $skipped
     */
    private function outcomeDetails(Collection $records, array $skipped, Page $parent, bool $includeMoved): string
    {
        $lines = [];
        $skippedById = collect($skipped)->keyBy('id');

        foreach ($records as $record) {
            if (! Gate::allows('view', $record)) {
                continue;
            }

            $skip = $skippedById->get((int) $record->getKey());
            if ($skip !== null) {
                $lines[] = __('capell-admin::page_moves.skipped', [
                    'page' => e($record->name),
                    'reason' => match ($skip['reason']) {
                        'cross_sites' => __('capell-admin::page_moves.reasons.cross_sites'),
                        'cycle' => __('capell-admin::page_moves.reasons.cycle'),
                        'unauthorized' => __('capell-admin::page_moves.reasons.unauthorized'),
                        'target_parent_denied' => __('capell-admin::page_moves.reasons.target_parent_denied'),
                        default => __('capell-admin::page_moves.reasons.unavailable'),
                    },
                ]);
            } elseif ($includeMoved) {
                $lines[] = __('capell-admin::page_moves.moved', ['page' => e($record->name), 'parent' => e($parent->name)]);
            }
        }

        return $lines === [] ? (string) __('capell-admin::bulk_actions.move_pages_none_moved_body') : implode(' ', $lines);
    }
}
