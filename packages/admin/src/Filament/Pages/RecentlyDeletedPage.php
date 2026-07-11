<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Filament\Notifications\Notification;
use Filament\Pages\Page as FilamentPage;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Central "Recently Deleted" view across the soft-deletable resources.
 *
 * Phase 4 closed the per-resource trash gap; this page is the cross-cutting
 * recovery surface. MVP covers Pages + Media (the two highest-traffic
 * soft-delete sources); Layouts/Blueprints/Sites can be added by extending
 * the collectGroups() method without touching the view.
 */
class RecentlyDeletedPage extends FilamentPage
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static ?string $slug = 'recently-deleted';

    protected static ?int $navigationSort = 90;

    protected string $view = 'capell-admin::filament.pages.recently-deleted';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::generic.recently_deleted');
    }

    #[Override]
    public function getTitle(): string
    {
        return __('capell-admin::generic.recently_deleted');
    }

    public function restoreRecord(string $resource, int $id): void
    {
        $model = match ($resource) {
            'page' => Page::onlyTrashed()->find($id),
            'media' => Media::onlyTrashed()->find($id),
            default => null,
        };

        if ($model === null) {
            return;
        }

        $model->restore();

        Notification::make()
            ->title(__('capell-admin::message.recently_deleted_restored'))
            ->success()
            ->send();
    }

    public function forceDeleteRecord(string $resource, int $id): void
    {
        $model = match ($resource) {
            'page' => Page::onlyTrashed()->find($id),
            'media' => Media::onlyTrashed()->find($id),
            default => null,
        };

        if ($model === null) {
            return;
        }

        $model->forceDelete();

        Notification::make()
            ->title(__('capell-admin::message.recently_deleted_force_deleted'))
            ->warning()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function getViewData(): array
    {
        return [
            'groups' => $this->collectGroups(),
        ];
    }

    /**
     * @return list<array{label: string, icon: string, resource: string, items: Collection<int, Model>}>
     */
    private function collectGroups(): array
    {
        /** @var Collection<int, Model> $deletedPages */
        $deletedPages = new Collection(Page::onlyTrashed()->latest('deleted_at')->limit(50)->get()->all());

        /** @var Collection<int, Model> $deletedMedia */
        $deletedMedia = new Collection(Media::onlyTrashed()->latest('deleted_at')->limit(50)->get()->all());

        return [
            [
                'label' => (string) __('capell-admin::generic.pages'),
                'icon' => 'heroicon-o-document-text',
                'resource' => 'page',
                'items' => $deletedPages,
            ],
            [
                'label' => (string) __('capell-admin::generic.media'),
                'icon' => 'heroicon-o-photo',
                'resource' => 'media',
                'items' => $deletedMedia,
            ],
        ];
    }
}
