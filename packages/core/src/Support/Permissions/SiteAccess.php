<?php

declare(strict_types=1);

namespace Capell\Core\Support\Permissions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\ContentLock;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\PageWorkflowState;
use Capell\Core\Models\PublicRenderContractEvent;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Activitylog\Models\Activity;

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
        // Queue workers reuse the bootstrap request, and grants can be revoked
        // within an HTTP request through writes that emit no model event.
        // Always read the live boundary; callers may retain an explicit snapshot.
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

        return $this->canUseRelatedOwner($media, 'model', 'model_type', 'model_id');
    }

    public function canUseRecord(Model $record): bool
    {
        if (! $this->authenticated) {
            return false;
        }

        if ($this->global) {
            return true;
        }

        if ($record instanceof Activity) {
            // Resolve from persisted ownership at action time; loaded morphs may
            // predate another administrator moving the subject to another site.
            return $this->query($record::class)->whereKey($record->getKey())->exists();
        }

        if ($record instanceof PageRevision || $record instanceof PageWorkflowState) {
            return $this->query(Page::class)->where('uuid', $record->getAttribute('page_uuid'))->exists();
        }

        if ($record instanceof ContentLock) {
            $ownerClass = Relation::getMorphedModel((string) $record->getAttribute('model_type'));

            return in_array($ownerClass, $this->ownerModels(), true)
                && $this->query($ownerClass)->whereKey($record->getAttribute('model_id'))->exists();
        }

        if ($record instanceof AssetAttachment) {
            return $this->canUseRelatedOwner($record, 'related', 'related_type', 'related_id');
        }

        if ($record instanceof TermPropertyValue) {
            return $this->query(Term::class)->whereKey($record->getAttribute('term_id'))->exists();
        }

        if ($record instanceof Media) {
            return $this->canUseMedia($record);
        }

        if ($record instanceof Term) {
            return $this->query(Taxonomy::class)->whereKey($record->getAttribute('taxonomy_id'))->exists();
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

        if ($model instanceof Activity) {
            return $this->scopeRelatedOwner($query, 'subject');
        }

        if ($model instanceof Translation) {
            return $this->scopeRelatedOwner($query, 'translatable');
        }

        if ($model instanceof TermPropertyValue) {
            return $query->whereHas('term', fn (Builder $term): Builder => $this->scope($term));
        }

        if ($model instanceof PageRevision || $model instanceof PageWorkflowState) {
            return $query->whereIn($model->qualifyColumn('page_uuid'), $this->query(Page::class)->select('uuid'));
        }

        if ($model instanceof ContentLock) {
            return $query->where(function (Builder $locks): void {
                foreach ($this->ownerModels() as $ownerClass) {
                    $owner = new $ownerClass;
                    $locks->orWhere(fn (Builder $owned): Builder => $owned
                        ->where('model_type', $owner->getMorphClass())
                        ->whereIn('model_id', $this->query($ownerClass)->select($owner->getKeyName())));
                }
            });
        }

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

        if ($owner instanceof Media) {
            return $this->canUseMedia($owner);
        }

        if ($owner instanceof Translation) {
            return $this->canUseRelatedOwner($owner, 'translatable', 'translatable_type', 'translatable_id');
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

        $owners = $this->relatedOwnerModels($relation);

        return $query->where(function (Builder $owned) use ($relation, $owners): void {
            $owned->whereHasMorph($relation, $owners, fn (Builder $owner): Builder => $this->scope($owner));

            if ($relation !== 'translatable') {
                $owned->orWhereHasMorph($relation, [Translation::class], fn (Builder $translation): Builder => $relation === 'subject'
                    ? $this->scope($translation)
                    : $translation->whereHasMorph('translatable', $owners, fn (Builder $owner): Builder => $this->scope($owner)));
            }

            if (in_array($relation, ['subject', 'translatable'], true)) {
                $owned->orWhereHasMorph($relation, [Media::class], fn (Builder $media): Builder => $this->scopeMedia($media));
            }
        });
    }

    /** @return list<class-string<Model>> */
    private function ownerModels(): array
    {
        // Only registered morph owners can be logged or attached. Unknown and
        // orphaned attributions remain global-only rather than falling open.
        return array_values(array_intersect(array_unique([
            ...CapellCore::getPageVariationModels(),
            Site::class, Layout::class, Translation::class, Term::class,
            TermPropertyValue::class, PageRevision::class, PageWorkflowState::class,
            SiteDomain::class, PageUrl::class,
            Taxonomy::class, PagePropertyValue::class,
        ]), array_values(Relation::morphMap())));
    }

    private function canUseRelatedOwner(Model $record, string $relation, string $typeColumn, string $idColumn): bool
    {
        $type = $record->getAttribute($typeColumn);
        $id = $record->getAttribute($idColumn);
        $class = is_string($type) ? Relation::getMorphedModel($type) : null;
        $assetRelation = in_array($relation, ['model', 'related'], true);
        $direct = $this->relatedOwnerModels($relation);
        if (! $assetRelation) {
            $direct[] = Media::class;
        }

        $owners = $assetRelation ? [...$direct, Translation::class] : $direct;

        if ($class === null || ! in_array($class, $owners, true) || ! is_int($id) && ! is_string($id)) {
            return false;
        }

        // Resolve proposed reference values afresh. Keep the same finite morph
        // graph as query scoping, including the narrower asset-owner contract.
        $query = $this->query($class)->whereKey($id);
        if ($assetRelation && $class === Translation::class) {
            $query->whereHasMorph('translatable', $direct, fn (Builder $owner): Builder => $this->scope($owner));
        }

        return $query->exists();
    }

    /** @return list<class-string<Model>> */
    private function relatedOwnerModels(string $relation): array
    {
        return in_array($relation, ['model', 'related'], true)
            ? [...CapellCore::getPageVariationModels(), Site::class, Layout::class]
            : array_values(array_diff($this->ownerModels(), [Translation::class]));
    }
}
