<?php

declare(strict_types=1);

namespace Capell\Core\Support\Permissions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PublicRenderContractEvent;
use Capell\Core\Models\Site;
use Capell\Core\Models\Term;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/** An actor and active-team snapshot; never register this as a singleton. */
final readonly class SiteAccess
{
    /** @param list<int> $siteIds */
    private function __construct(
        private bool $authenticated,
        private bool $global,
        private array $siteIds,
    ) {}

    public static function current(): self
    {
        return self::forActor(auth()->user());
    }

    public static function forActor(?Authenticatable $actor, bool $acrossAssignedSites = false): self
    {
        if (! $actor instanceof Authenticatable) {
            return new self(false, false, []);
        }

        $global = self::isGlobalActor($actor);
        $siteIds = ! $global && method_exists($actor, 'getAssignedSiteIds')
            ? ($acrossAssignedSites && method_exists($actor, 'getAllAssignedSiteIds') ? $actor->getAllAssignedSiteIds() : $actor->getAssignedSiteIds())->map(fn (mixed $id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values()->all()
            : [];

        return new self(true, $global, $siteIds);
    }

    /** @return list<int>|null Null denotes unrestricted global access. */
    public function allowedSiteIds(): ?array
    {
        return $this->global ? null : $this->siteIds;
    }

    public function isGlobal(): bool
    {
        return $this->global;
    }

    public function can(Site $site): bool
    {
        return $this->canSiteId((int) $site->getKey());
    }

    public function canSiteId(int $siteId): bool
    {
        return $this->authenticated && ($this->global || in_array($siteId, $this->siteIds, true));
    }

    public function site(int|string|null $siteId, bool $fallbackToDefault = true): ?Site
    {
        $site = filled($siteId) ? $this->query(Site::class)->find($siteId) : null;

        return $site ?? ($fallbackToDefault ? $this->query(Site::class)->default()->first() : null);
    }

    /** @return array<string, string> */
    public function layoutGroups(): array
    {
        if ($this->global) {
            return Layout::getGroups();
        }

        return $this->query(Layout::class)->select('group')->selectRaw('COUNT(*) as group_count')
            ->whereNotNull('group')->groupBy('group')->orderBy('group')->get()
            ->mapWithKeys(fn (Layout $layout): array => [(string) $layout->group => $layout->group . ' (' . (int) $layout->getAttribute('group_count') . ')'])->all();
    }

    public function canUseLayout(Layout $layout): bool
    {
        return $this->authenticated && ($layout->site_id === null || $this->canSiteId($layout->site_id));
    }

    public function canUseMedia(Media $media): bool
    {
        if (! $this->authenticated) {
            return false;
        }

        if ($this->global) {
            return true;
        }

        $media->loadMissing('model');

        return $this->canUseOwner($media->model);
    }

    public function canUseRecord(Model $record): bool
    {
        if (! $this->authenticated) {
            return false;
        }

        if ($this->global) {
            return true;
        }

        if ($record instanceof Media) {
            return $this->canUseMedia($record);
        }

        if ($record instanceof Term) {
            $record->loadMissing('taxonomy');

            return $record->taxonomy !== null && $this->canSiteId((int) $record->taxonomy->site_id);
        }

        if ($record instanceof Site || $record instanceof Layout || $record instanceof Pageable || $record instanceof Translation) {
            return $this->canUseOwner($record);
        }

        return $record->hasAttribute('site_id') && $this->canSiteId((int) $record->getAttribute('site_id'));
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    public function query(string $model): Builder
    {
        $instance = new $model;

        return $this->scope($instance->newQuery()->setModel($instance));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query, ?string $column = null): Builder
    {
        if (! $this->authenticated) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->global) {
            return $query;
        }

        $model = $query->getModel();

        if ($model instanceof Media) {
            return $this->scopeMedia($query);
        }

        if ($model instanceof AssetAttachment) {
            return $this->scopeRelatedOwner($query, 'related');
        }

        if ($model instanceof PublicRenderContractEvent) {
            return $this->scopeRenderEvents($query);
        }

        if ($model instanceof Term) {
            return $query->whereHas('taxonomy', fn (Builder $taxonomy): Builder => $this->scope($taxonomy));
        }

        $column ??= $model->qualifyColumn($model instanceof Site ? $model->getKeyName() : 'site_id');

        if ($model instanceof Layout) {
            return $query->where(fn (Builder $nested): Builder => $nested->whereNull($column)->orWhereIn($column, $this->siteIds));
        }

        return $query->whereIn($column, $this->siteIds);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeMedia(Builder $query): Builder
    {
        return $this->scopeRelatedOwner($query, 'model');
    }

    /** @param Builder<AssetAttachment> $query
     * @return Builder<AssetAttachment>
     */
    public function scopeAssetAttachments(Builder $query): Builder
    {
        return $this->scopeRelatedOwner($query, 'related');
    }

    public function trackedUsageCount(Media $media): int
    {
        return $this->scopeAssetAttachments($media->assetRelations()->getQuery())->count();
    }

    private static function isGlobalActor(Authenticatable $actor): bool
    {
        if (method_exists($actor, 'isGlobalAdmin')) {
            return $actor->isGlobalAdmin();
        }

        if (! method_exists($actor, 'hasRole')) {
            return false;
        }

        $configured = config('capell.roles.super_admin', config('filament-shield.super_admin.name', 'super_admin'));
        $role = is_string($configured) && $configured !== '' ? $configured : 'super_admin';

        return PermissionTeamContext::run(null, fn (): bool => $actor->hasRole($role), $actor instanceof Model ? $actor : null);
    }

    private function canUseOwner(?Model $owner): bool
    {
        if ($owner instanceof Site) {
            return $this->can($owner);
        }

        if ($owner instanceof Layout) {
            return $this->canUseLayout($owner);
        }

        if ($owner instanceof Pageable) {
            return $this->canSiteId((int) $owner->getAttribute('site_id'));
        }

        if ($owner instanceof Translation) {
            $owner->loadMissing('translatable');

            return $this->canUseOwner($owner->translatable);
        }

        return false;
    }

    /** @template TModel of Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopeRenderEvents(Builder $query): Builder
    {
        // Use the most specific attribution. A shared theme must not make a
        // foreign page's failure visible; unattributed events are global-only.
        return $query->where(function (Builder $events): void {
            $events->whereIn('page_id', $this->query(Page::class)->select('id'))
                ->orWhere(fn (Builder $layouts): Builder => $layouts->whereNull('page_id')
                    ->whereIn('layout_id', Layout::query()->whereIn('site_id', $this->siteIds)->select('id')))
                ->orWhere(fn (Builder $themes): Builder => $themes->whereNull('page_id')->whereNull('layout_id')
                    ->whereIn('theme_id', Theme::query()->whereHas('sites', fn (Builder $sites): Builder => $this->scope($sites))->select('id')));
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopeRelatedOwner(Builder $query, string $relation): Builder
    {
        if (! $this->authenticated) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->global) {
            return $query;
        }

        $assignedSiteIds = collect($this->siteIds);

        return $query->where(function (Builder $nestedQuery) use ($assignedSiteIds, $relation): void {
            $nestedQuery
                ->whereHasMorph(
                    $relation,
                    [
                        ...CapellCore::getPageVariationModels(),
                        Site::class,
                        Layout::class,
                    ],
                    function (Builder $ownerQuery, string $ownerType) use ($assignedSiteIds): Builder {
                        $ownerClass = Relation::getMorphedModel($ownerType) ?? $ownerType;

                        if (is_a($ownerClass, Site::class, true)) {
                            return $ownerQuery->whereIn('id', $assignedSiteIds);
                        }

                        if (is_a($ownerClass, Layout::class, true)) {
                            return $ownerQuery->where(
                                fn (Builder $layoutQuery): Builder => $layoutQuery
                                    ->whereNull('site_id')
                                    ->orWhereIn('site_id', $assignedSiteIds),
                            );
                        }

                        return $ownerQuery->whereIn('site_id', $assignedSiteIds);
                    },
                )
                ->orWhereHasMorph(
                    $relation,
                    [Translation::class],
                    fn (Builder $translationQuery): Builder => $translationQuery->whereHasMorph(
                        'translatable',
                        [
                            ...CapellCore::getPageVariationModels(),
                            Site::class,
                            Layout::class,
                        ],
                        function (Builder $translatableQuery, string $translatableType) use ($assignedSiteIds): Builder {
                            $translatableClass = Relation::getMorphedModel($translatableType) ?? $translatableType;

                            if (is_a($translatableClass, Site::class, true)) {
                                return $translatableQuery->whereIn('id', $assignedSiteIds);
                            }

                            return $this->scope($translatableQuery);
                        },
                    ),
                );
        });
    }
}
