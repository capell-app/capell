<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Actions\RestorePageCascadeAction;
use Capell\Admin\Filament\Actions\ForceDeleteAction;
use Capell\Admin\Filament\Concerns\Validate\PageValidation;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Actions\Enums\ActionStatus;
use Filament\Notifications\Notification;
use Filament\Pages\Page as FilamentPage;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Override;

/**
 * Central "Recently Deleted" view across the soft-deletable resources.
 *
 * Phase 4 closed the per-resource trash gap; this page is the cross-cutting
 * recovery surface. MVP covers Pages + Media (the two highest-traffic
 * soft-delete sources); Layouts/Blueprints/Sites can be added by extending
 * the collectGroups() method without touching the view.
 */
class RecentlyDeletedPage extends FilamentPage implements ValidatesDelete
{
    use HasPageShield;
    use PageValidation;

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
            'page' => SiteAccess::current()->query(Page::class)->onlyTrashed()->find($id),
            'media' => SiteAccess::current()->query(Media::class)->onlyTrashed()->find($id),
            default => null,
        };

        if ($model === null) {
            return;
        }

        $result = $model instanceof Page ? RestorePageCascadeAction::make()->restoreWithResult($model) : null;
        $restored = $result !== null
            ? $result->restored
            : $model->getConnection()->transaction(function () use ($model): bool {
                $locked = SiteAccess::current()->query($model::class)->onlyTrashed()->whereKey($model->getKey())->lockForUpdate()->first();
                if ($locked === null) {
                    return false;
                }

                Gate::authorize('restore', $locked);

                return $locked->restore();
            });

        if (! $restored) {
            Notification::make()
                ->title(__('capell-admin::message.recently_deleted_restore_cascade_denied'))
                ->warning()
                ->send();

            return;
        }

        $notification = Notification::make()->title(__('capell-admin::message.recently_deleted_restored'));
        if ($result?->notice !== null) {
            $notification->body($result->notice)->warning();
        } else {
            $notification->success();
        }

        $notification->send();
    }

    public function forceDeleteRecord(string $resource, int $id): void
    {
        $model = match ($resource) {
            'page' => SiteAccess::current()->query(Page::class)->onlyTrashed()->find($id),
            'media' => SiteAccess::current()->query(Media::class)->onlyTrashed()->find($id),
            default => null,
        };

        if ($model === null) {
            return;
        }

        $action = ForceDeleteAction::make()
            ->record($model)
            ->livewire($this)
            ->successNotification(Notification::make()
                ->title(__('capell-admin::message.recently_deleted_force_deleted'))
                ->warning());

        try {
            $action->callBefore();
            $action->call();
            $action->callAfter();
        } catch (Halt) {
            return;
        }

        if ($action->getStatus() === ActionStatus::Success) {
            $action->sendSuccessNotification();
        } else {
            $action->sendFailureNotification();
        }

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
        $deletedPages = new Collection(SiteAccess::current()->query(Page::class)->onlyTrashed()->with(['blueprint.roleRestrictions', 'site'])->latest('deleted_at')->limit(50)->get()->filter(fn (Page $page): bool => Gate::allows('view', $page))->all());

        /** @var Collection<int, Model> $deletedMedia */
        $deletedMedia = new Collection(SiteAccess::current()->query(Media::class)->onlyTrashed()->latest('deleted_at')->limit(50)->get()->all());

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
