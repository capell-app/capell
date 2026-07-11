<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Admin\Actions\Pages\BulkMovePagesAction;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
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
                    ->getSearchResultsUsing(fn (string $search): array => SiteScope::applyForCurrentActor($pageModel::query())
                        ->where('name', 'like', sprintf('%%%s%%', $search))
                        ->limit(50)
                        ->pluck('name', 'id')
                        ->all())
                    ->getOptionLabelUsing(function (mixed $value) use ($pageModel): ?string {
                        $page = SiteScope::applyForCurrentActor($pageModel::query())->find($value);

                        return $page instanceof Page ? $page->name : null;
                    }),
                Toggle::make('add_redirects')
                    ->label(__('capell-admin::bulk_actions.move_pages_add_redirects'))
                    ->default(false),
            ])
            ->action(function (Collection $records, array $data) use ($pageModel): void {
                /** @var (Page&Pageable)|null $newParent */
                $newParent = $pageModel::query()->find($data['parent_id']);

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
                    Notification::make()
                        ->title(__('capell-admin::bulk_actions.move_pages_failed'))
                        ->body(__('capell-admin::bulk_actions.move_pages_failed_body', [
                            'page' => $result['failed_at']['name'] !== '' ? $result['failed_at']['name'] : '#' . $result['failed_at']['id'],
                            'reason' => $result['failed_at']['reason'],
                        ]))
                        ->danger()
                        ->send();

                    return;
                }

                if ($result['moved'] === 0) {
                    Notification::make()
                        ->title(__('capell-admin::bulk_actions.move_pages_none_moved'))
                        ->body(__('capell-admin::bulk_actions.move_pages_none_moved_body'))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('capell-admin::bulk_actions.move_pages_done', [
                        'moved' => $result['moved'],
                        'skipped' => $result['skipped'],
                    ]))
                    ->success()
                    ->send();
            });
    }

    public static function getDefaultName(): ?string
    {
        return 'bulk-move-pages';
    }
}
