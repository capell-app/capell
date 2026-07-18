<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Aimeos\Nestedset\Collection;
use Aimeos\Nestedset\NodeTrait;
use Bkwld\Cloner\Cloneable;
use Capell\Core\Actions\GetPageUrlPathAction;
use Capell\Core\Actions\ResolveFirstPageByTypeAction;
use Capell\Core\Actions\ValidatePageHierarchyAction;
use Capell\Core\Concerns\HasCapellMedia;
use Capell\Core\Concerns\WhenBootedShim;
use Capell\Core\Contracts\DraftableContract;
use Capell\Core\Contracts\Media\HasMediaContract;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Database\Factories\PageFactory;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Enums\PageOrderEnum;
use Capell\Core\EventSourcing\Aggregates\PageAggregate;
use Capell\Core\EventSourcing\Concerns\IsEventSourced;
use Capell\Core\EventSourcing\Contracts\EventSourced;
use Capell\Core\EventSourcing\Contracts\EventSourcedStateSerializer;
use Capell\Core\EventSourcing\Serializers\PageStateSerializer;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Concerns\CloneableExcept;
use Capell\Core\Models\Concerns\HasAssets;
use Capell\Core\Models\Concerns\HasBlueprint;
use Capell\Core\Models\Concerns\HasBlueprints;
use Capell\Core\Models\Concerns\HasMetaData;
use Capell\Core\Models\Concerns\HasMorphModelRelations;
use Capell\Core\Models\Concerns\HasPageOrdering;
use Capell\Core\Models\Concerns\HasPublishDates;
use Capell\Core\Models\Concerns\HasTranslations;
use Capell\Core\Models\Concerns\HasUserstamps;
use Capell\Core\Models\Contracts\Blueprintable;
use Capell\Core\Models\Contracts\Publishable;
use Capell\Core\Models\Contracts\Translatable;
use Capell\Core\Models\Contracts\Userstampable;
use Capell\Core\Models\Scopes\LanguagesOrderScope;
use Capell\Core\Observers\PageObserver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Arr;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Staudenmeir\EloquentJsonRelations\HasJsonRelationships;
use Staudenmeir\EloquentJsonRelations\Relations\BelongsToJson;

/**
 * @property int $id
 * @property string|null $uuid
 * @property string $name
 * @property int $blueprint_id
 * @property int $layout_id
 * @property int $site_id
 * @property int|null $parent_id
 * @property array<array-key, mixed>|null $meta
 * @property array<array-key, mixed>|null $admin
 * @property CarbonImmutable|null $visible_from
 * @property CarbonImmutable|null $visible_until
 * @property int $order
 * @property int $_lft
 * @property int $_rgt
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Media|null $image
 * @property-read Media|null $socialImage
 * @property-read MediaCollection<int, Media> $media
 * @property-read int|null $media_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Activity> $activities
 * @property-read int|null $activities_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AssetAttachment> $assetRelations
 * @property-read int|null $asset_attachments_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AssetAttachment> $assets
 * @property-read int|null $assets_count
 * @property-read Model|null $author
 * @property-read Page|null $parent
 * @property-read Page|null $canonicalPage
 * @property-read Collection<int, Page> $canonicalPages
 * @property-read int|null $canonical_pages_count
 * @property-read Collection<int, Page> $children
 * @property-read int|null $children_count
 * @property-read Model|null $creator
 * @property-read Model|null $destroyer
 * @property-read Model|null $editor
 * @property-read string|null $title
 * @property-read bool $has_title_or_content
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Language> $languages
 * @property-read int|null $languages_count
 * @property-read Layout $layout
 * @property-read PageUrl $pageUrl
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PageUrl> $pageUrls
 * @property-read int|null $page_urls_count
 * @property-read Page|null $publishedPage
 * @property-read Model|null $publisher
 * @property-read Collection<int, Page> $revisions
 * @property-read int|null $revisions_count
 * @property-read Collection<int, Page> $siblings
 * @property-read int|null $siblings_count
 * @property-read Site $site
 * @property-read Translation|null $translation
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Translation> $translations
 * @property-read int|null $translations_count
 * @property-read Blueprint $blueprint
 * @property-read array<string, mixed>|null $url_params
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Page> $related
 * @property-read int|null $related_count
 *
 * @method static Collection<int, static> all($columns = ['*'])
 * @method static Builder<static> alphabetical(Language $language, string $direction = 'asc')
 * @method static Builder<static> ancestorsAndSelf($id, array<int, string> $columns = [])
 * @method static Builder<static> ancestorsOf($id, array<int, string> $columns = [])
 * @method static Builder<static> applyNestedSetScope(?string $table = null)
 * @method static Builder<static> countErrors()
 * @method static Builder<static> d()
 * @method static Builder<static> defaultOrder(string $dir = 'asc')
 * @method static Collection<int, static> descendantsAndSelf($id, array<int, string> $columns = [])
 * @method static Collection<int, static> descendantsOf($id, array<int, string> $columns = [], $andSelf = false)
 * @method static Builder<static> excludeRevision(Model|int $exclude)
 * @method static Builder<static> expired()
 * @method static PageFactory factory($count = null, $state = [])
 * @method static Builder<static> fixSubtree($root)
 * @method static Builder<static> fixTree($root = null)
 * @method static Collection<int, static> get($columns = ['*'])
 * @method static Builder<static> getNodeData($id, $required = false)
 * @method static Builder<static> getPlainNodeData($id, $required = false)
 * @method static Builder<static> getTotalErrors()
 * @method static Builder<static> hasChildren()
 * @method static Builder<static> homePage()
 * @method static Builder<static> isBroken()
 * @method static Builder<static> latest()
 * @method static Builder<static> defaultOrdering()
 * @method static Builder<static> leaves(array<int, string> $columns = [])
 * @method static Builder<static> makeGap(int $cut, int $height)
 * @method static Builder<static> moveNode($key, $position)
 * @method static Builder<static> newModelQuery()
 * @method static Builder<static> newQuery()
 * @method static Builder<static> notHomePage()
 * @method static Builder<static> onlyTrashed()
 * @method static Builder<static> publishedDate()
 * @method static Builder<static> publishedLatest()
 * @method static Builder<static> publishedOldest()
 * @method static Builder<static> orWhereAncestorOf(bool $id, bool $andSelf = false)
 * @method static Builder<static> orWhereDescendantOf($id)
 * @method static Builder<static> orWhereNodeBetween($values)
 * @method static Builder<static> orWhereNotDescendantOf($id)
 * @method static Builder<static> ordered(string $dir = 'asc')
 * @method static Builder<static> query()
 * @method static Builder<static> rebuildSubtree($root, array<int, mixed> $data, $delete = false)
 * @method static Builder<static> rebuildTree(array<int, mixed> $data, $delete = false, $root = null)
 * @method static Builder<static> reversed()
 * @method static Builder<static> root(array<int, string> $columns = [])
 * @method static Builder<static> whereAdmin($value)
 * @method static Builder<static> whereAncestorOf($id, $andSelf = false, $boolean = 'and')
 * @method static Builder<static> whereAncestorOrSelf($id)
 * @method static Builder<static> whereCreatedAt($value)
 * @method static Builder<static> whereCreatedBy($value)
 * @method static Builder<static> whereDeletedAt($value)
 * @method static Builder<static> whereDeletedBy($value)
 * @method static Builder<static> whereDescendantOf($id, $boolean = 'and', $not = false, $andSelf = false)
 * @method static Builder<static> whereDescendantOrSelf(string $id, string $boolean = 'and', string $not = false)
 * @method static Builder<static> whereHasLanguage(Language $language)
 * @method static Builder<static> whereId($value)
 * @method static Builder<static> whereIsAfter($id, $boolean = 'and')
 * @method static Builder<static> whereIsBefore($id, $boolean = 'and')
 * @method static Builder<static> whereIsLeaf()
 * @method static Builder<static> whereIsRoot()
 * @method static Builder<static> whereLayoutId($value)
 * @method static Builder<static> whereLft($value)
 * @method static Builder<static> whereMeta($value)
 * @method static Builder<static> whereName($value)
 * @method static Builder<static> whereNodeBetween($values, $boolean = 'and', $not = false, $query = null)
 * @method static Builder<static> whereNotDescendantOf($id)
 * @method static Builder<static> whereOrder($value)
 * @method static Builder<static> whereParentId($value)
 * @method static Builder<static> whereVisibleFrom($value)
 * @method static Builder<static> wherePublishTo($value)
 * @method static Builder<static> whereRgt($value)
 * @method static Builder<static> whereSettings($value)
 * @method static Builder<static> whereSiteId($value)
 * @method static Builder<static> whereBlueprintId($value)
 * @method static Builder<static> whereUpdatedAt($value)
 * @method static Builder<static> whereUpdatedBy($value)
 * @method static Builder<static> withAssets()
 * @method static Builder<static> withDepth(string $as = 'depth')
 * @method static Builder<static> withTrashed(bool $withTrashed = true)
 * @method static Builder<static> withWhereHasLanguage(int $language_id)
 * @method static Builder<static> withoutRoot()
 * @method static Builder<static> withoutSelf()
 * @method static Builder<static> withoutTrashed()
 * @method null|static duplicate(mixed $attr = null)
 *
 * @implements Pageable<$this>
 *
 * @mixin Model
 */
#[ObservedBy(PageObserver::class)]
class Page extends Model implements Blueprintable, DraftableContract, EventSourced, HasMedia, HasMediaContract, Pageable, Publishable, Translatable, Userstampable
{
    use Cloneable;
    use CloneableExcept;
    use HasAssets;
    use HasBlueprint;
    use HasBlueprints;
    use HasCapellMedia;

    /** @use HasFactory<PageFactory> */
    use HasFactory;

    use HasJsonRelationships;
    use HasMetaData;
    use HasMorphModelRelations;

    /** @use HasPageOrdering<Page> */
    use HasPageOrdering;

    use HasPublishDates;
    use HasTranslations;
    use HasUserstamps;
    use IsEventSourced;
    use LogsActivity;
    use NodeTrait;
    use SoftDeletes;
    use WhenBootedShim;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'admin',
        'content_structure_override',
        'layout_id',
        'meta',
        'name',
        'order',
        'parent_id',
        'uuid',
        'visible_from',
        'visible_until',
        'site_id',
        'blueprint_id',
    ];

    /** @var list<string> */
    protected array $clone_exempt_attributes = [
        'hidden',
    ];

    protected static string $factory = PageFactory::class;

    public static function hasPageHierarchy(): bool
    {
        return true;
    }

    public static function defaultOrdering(): PageOrderEnum
    {
        return PageOrderEnum::Default;
    }

    /**
     * @param  list<string>  $relations
     */
    public static function getSiteHomePage(Site $site, ?Language $language = null, array $relations = ['translations.language']): ?self
    {
        if (! $language instanceof Language) {
            $language = $site->language;
        }

        return self::query()->withWhereHasLanguage($language->id)
            ->where('site_id', $site->id)
            ->publishedDate()
            ->homePage()
            ->first();
    }

    public static function getFirstPageByTypeForSite(
        string $key,
        Site $site,
        ?Language $language = null,
        ?callable $modifyQueryUsing = null,
    ): ?self {
        return ResolveFirstPageByTypeAction::run($key, $site, $language, $modifyQueryUsing);
    }

    public static function getDefaultType(?string $group): ?Blueprint
    {
        $query = Blueprint::query()->pageType();

        if ($group !== null) {
            $query->adminResource($group);
        }

        return $query->orderByRaw('CASE WHEN `default` = 1 THEN 0 ELSE 1 END')
            ->ordered()
            ->first();
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function getMorphRelations(?Language $language = null, bool $normalizeKey = false): array
    {
        $languageIds = [];
        if ($language instanceof Language) {
            $languageIds[] = $language->id;
        }

        $base = [
            'ancestors',
            'creator',
            'media',
            'site',
            'image',
            'translation' => fn (BuilderContract $query): BuilderContract => $query->with('language')
                ->tap(
                    fn (Builder $query): BuilderContract => LanguagesOrderScope::applyTo($query, $languageIds),
                ),
            'blueprint',
            'pageUrl' => fn (BuilderContract $query): BuilderContract => $query->with('siteDomain')
                ->tap(
                    fn (Builder $query): BuilderContract => LanguagesOrderScope::applyTo($query, $languageIds),
                ),
        ];

        return static::mergeMorphRelationDefinitions($base, self::class, $language, $normalizeKey);
    }

    public static function isBrokenWithoutPublish(): bool
    {
        return ValidatePageHierarchyAction::run();
    }

    public static function setResolvedPageUrlSiteDomain(Page $page, Site $site): void
    {
        $pageUrl = $page->pageUrl;

        $matchingSiteDomain = $site->relationLoaded('siteDomains')
            ? $site->siteDomains->firstWhere('language_id', $pageUrl->language_id)
            : null;

        if (! $matchingSiteDomain instanceof SiteDomain) {
            $matchingSiteDomain = SiteDomain::query()
                ->where('site_id', $pageUrl->site_id)
                ->where('language_id', $pageUrl->language_id)
                ->first();

            if (! $matchingSiteDomain instanceof SiteDomain) {
                return;
            }

        }

        $pageUrl->setRelation('siteDomain', $matchingSiteDomain);
    }

    /**
     * Replicate the page but drop the shared uuid so a fresh one is generated
     * on save. CopyOnWriteAction preserves the live uuid explicitly when it
     * needs the clone to share identity with the live row.
     *
     * @param  array<string>|null  $except
     */
    #[Override]
    public function replicate(?array $except = null): Model
    {
        $except = array_unique(array_merge($except ?? [], ['uuid']));

        return parent::replicate($except);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('page')
            ->logAll()
            ->logExcept([
                'updated_at',
                'created_at',
                'deleted_at',
                '_lft',
                '_rgt',
                'created_by',
                'updated_by',
                'deleted_by',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * @param  array<string, mixed>|string  $input
     */
    public function mergeMeta(array|string $input, mixed $value = null): void
    {
        $this->meta = array_replace_recursive(
            $this->meta ?? [],
            is_string($input)
                ? Arr::undot([$input => $value])
                : Arr::undot($input),
        );
    }

    public function getParentUrl(Language $language, bool $fullUrl = false): string
    {
        return GetPageUrlPathAction::run($this, $language, $fullUrl);
    }

    public function loadParent(Language $language): void
    {
        $this->load([
            // Use whereHas and select on translation to filter by language, avoiding non-standard whereHasLanguage()
            'parent' => fn (Relation $query): Relation => $query->whereHas(
                'translation',
                fn (BuilderContract $query): BuilderContract => $query->where('language_id', $language->id),
            ),
        ]);
    }

    public function shouldLogVisit(): bool
    {
        return (bool) ($this->blueprint?->meta['disable_visit_logs'] ?? true);
    }

    /**
     * @return list<string>
     */
    public function getCloneableRelations(): array
    {
        return CapellCore::getCloneableRelations('page');
    }

    public function getPublishDate(): ?CarbonImmutable
    {
        $date = $this->created_at;

        return $date !== null ? CarbonImmutable::make($date) : null;
    }

    public function getDraftKey(): string
    {
        return (string) $this->getKey();
    }

    /**
     * @return Collection<int, Page>
     */
    public function getSiblingsExcludingSelf(): Collection
    {
        return $this->siblings()->where($this->getKeyName(), '<>', $this->getKey())->get();
    }

    /** @return MorphTo<Model, $this> */
    public function canonicalPage(): MorphTo
    {
        return $this->morphTo(type: 'meta->canonical_pageable_type', id: 'meta->canonical_pageable_id');
    }

    /** @return BelongsTo<Layout, $this> */
    public function layout(): BelongsTo
    {
        return $this->belongsTo(Layout::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return MorphOne<PageUrl, $this> */
    public function pageUrl(): MorphOne
    {
        return $this->morphOne(PageUrl::class, 'pageable')->withDefault(['site_id' => $this->site_id]);
    }

    /** @return MorphMany<PageUrl, $this> */
    public function pageUrls(): MorphMany
    {
        $model = $this->morphMany(PageUrl::class, 'pageable');

        $model->chaperone('pageable');

        return $model;
    }

    /** @return MorphMany<Page, $this> */
    public function canonicalPages(): MorphMany
    {
        return $this->morphMany(
            self::class,
            'canonical_pageable',
            'meta->canonical_pageable_type',
            'meta->canonical_pageable_id',
        );
    }

    /**
     * @return HasMany<Page, $this>
     */
    public function siblings(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id', 'parent_id');
    }

    /**
     * Explicit children relation for factories and UI.
     *
     * @return HasMany<Page, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }

    /**
     * @return BelongsToJson<Page, $this>
     */
    public function related(): BelongsToJson
    {
        return $this->belongsToJson(self::class, 'meta->related');
    }

    public function isErrorPage(): bool
    {
        return $this->blueprint->key === 'error';
    }

    /**
     * Returns true when the given user is allowed to access this page,
     * based on the role restrictions configured on the page's type.
     */
    public function isAccessibleByUser(User $user): bool
    {
        return $this->blueprint->isAccessibleByUser($user, $this->site);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(MediaCollectionEnum::Image->value)->singleFile();
        $this->addMediaCollection(MediaCollectionEnum::SocialImage->value)->singleFile();
    }

    /** @return MorphOne<Media, $this> */
    public function image(): MorphOne
    {
        return $this->morphOne(Media::class, 'model')
            ->where('collection_name', MediaCollectionEnum::Image->value);
    }

    /** @return MorphOne<Media, $this> */
    public function socialImage(): MorphOne
    {
        return $this->morphOne(Media::class, 'model')
            ->where('collection_name', MediaCollectionEnum::SocialImage->value);
    }

    /**
     * The aggregate that owns this page's event-sourced history. See the
     * IsEventSourced trait — these two declarations are the only opt-in a
     * model author writes.
     *
     * @return class-string<PageAggregate>
     */
    public function eventSourcedAggregate(): string
    {
        return PageAggregate::class;
    }

    public function eventSourcedSerializer(): EventSourcedStateSerializer
    {
        return resolve(PageStateSerializer::class);
    }

    /**
     * The event-sourcing revision index for this page, keyed by uuid. Backs the
     * admin history timeline and rollback relation manager.
     *
     * @return HasMany<PageRevision, $this>
     */
    public function pageRevisions(): HasMany
    {
        return $this->hasMany(PageRevision::class, 'page_uuid', 'uuid')
            ->orderByDesc('version');
    }

    /**
     * The effective content structure for this page.
     *
     * Returns the per-page override (set when an editor toggles HTML↔Blocks
     * for this page only) when present; otherwise falls through to the
     * page's Blueprint default. Use this anywhere admin routing, form
     * rendering, or rendering cares about the active authoring mode.
     */
    protected function contentStructure(): Attribute
    {
        return Attribute::make(get: function (): ?ContentStructure {
            $override = $this->getAttributeFromArray('content_structure_override');
            if (is_string($override) && $override !== '') {
                $cast = ContentStructure::tryFrom($override);
                if ($cast !== null) {
                    return $cast;
                }
            }

            return $this->blueprint->content_structure;
        });
    }

    /**
     * @param  Builder<self>  $query
     */
    protected function scopeHomePage(Builder $query): void
    {
        $query->whereRelation('blueprint', 'key', 'home');
    }

    /**
     * @param  Builder<self>  $query
     */
    protected function scopeNotHomePage(Builder $query): void
    {
        $query->whereRelation('blueprint', 'key', '!=', 'home');
    }

    /**
     * @param  Builder<self>|Relation<self, self, mixed>  $query
     */
    protected function scopeWhereHasLanguage(Builder|Relation $query, Language $language): void
    {
        $query->whereHas(
            'translation',
            fn (Builder $query) => $query->where('language_id', $language->id),
        )
            ->whereHas(
                'pageUrl',
                fn (Builder $query) => $query->where('language_id', $language->id),
            );
    }

    protected function hasTitleOrContent(): Attribute
    {
        return Attribute::make(get: function (): bool {
            if ($this->translation === null) {
                return false;
            }

            return (is_string($this->translation->title) && $this->translation->title !== '') || (is_string($this->translation->content) && $this->translation->content !== '');
        });
    }

    protected function urlParams(): Attribute
    {
        return Attribute::make(get: function (): ?array {
            if (! $this->relationLoaded('blueprint')) {
                return null;
            }

            return $this->blueprint?->meta['url_params'] ?? null;
        });
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'admin' => 'json',
            'meta' => 'json',
            'visible_from' => 'datetime',
            'visible_until' => 'datetime',
        ];
    }
}
