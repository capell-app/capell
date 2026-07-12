<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Bkwld\Cloner\Cloneable;
use Capell\Core\Concerns\HasCapellMedia;
use Capell\Core\Contracts\Media\HasMediaContract;
use Capell\Core\Database\Factories\SiteFactory;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Exceptions\SiteDomainNotFoundException;
use Capell\Core\Models\Concerns\HasBlueprint;
use Capell\Core\Models\Concerns\HasDefault;
use Capell\Core\Models\Concerns\HasMetaData;
use Capell\Core\Models\Concerns\HasStatus;
use Capell\Core\Models\Concerns\HasTranslations;
use Capell\Core\Models\Concerns\HasUserstamps;
use Capell\Core\Models\Contracts\Blueprintable;
use Capell\Core\Models\Contracts\Defaultable;
use Capell\Core\Models\Contracts\Statusable;
use Capell\Core\Models\Contracts\Translatable;
use Capell\Core\Models\Contracts\Userstampable;
use Capell\Core\Observers\SiteObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Collection;
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
 * @property string $name
 * @property int $blueprint_id
 * @property int $theme_id
 * @property int $language_id
 * @property array<array-key, mixed>|null $meta
 * @property array<array-key, mixed>|null $admin
 * @property int $order
 * @property bool $default
 * @property bool $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Media|null $image
 * @property-read Media|null $logo
 * @property-read Media|null $logoInverted
 * @property-read MediaCollection<int, Media> $media
 * @property-read int|null $media_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Activity> $activities
 * @property-read int|null $activities_count
 * @property-read AuthenticatableUser|null $creator
 * @property-read SiteDomain|null $defaultDomain
 * @property-read AuthenticatableUser|null $destroyer
 * @property-read AuthenticatableUser|null $editor
 * @property-read string|null $title
 * @property-read Language $language
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Language> $languages
 * @property-read int|null $languages_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Layout> $layouts
 * @property-read int|null $layouts_count
 * @property-read \Aimeos\Nestedset\Collection<int, Page> $pages
 * @property-read int|null $pages_count
 * @property-read SiteDomain|null $siteDomain
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SiteDomain> $siteDomains
 * @property-read int|null $site_domains_count
 * @property-read Theme $theme
 * @property-read Translation|null $translation
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Translation> $translations
 * @property-read int|null $translations_count
 * @property-read Blueprint $blueprint
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Site> $related
 * @property-read int|null $related_count
 *
 * @method static Builder<static>|Site default(bool $default = true)
 * @method static Builder<static>|Site disabled()
 * @method static Builder<static>|Site enabled()
 * @method static Builder<static>|Site excludingPreview()
 * @method static SiteFactory factory($count = null, array<string, mixed> $state = [])
 * @method static Builder<static>|Site newModelQuery()
 * @method static Builder<static>|Site newQuery()
 * @method static Builder<static>|Site nonDefault()
 * @method static Builder<static>|Site onlyTrashed()
 * @method static Builder<static>|Site ordered(string $dir = 'asc')
 * @method static Builder<static>|Site query()
 * @method static Builder<static>|Site status(bool $enabled)
 * @method static Builder<static>|Site whereAdmin($value)
 * @method static Builder<static>|Site whereCreatedAt($value)
 * @method static Builder<static>|Site whereCreatedBy($value)
 * @method static Builder<static>|Site whereDefault($value)
 * @method static Builder<static>|Site whereDeletedAt($value)
 * @method static Builder<static>|Site whereDeletedBy($value)
 * @method static Builder<static>|Site whereId($value)
 * @method static Builder<static>|Site whereLanguageId($value)
 * @method static Builder<static>|Site whereMeta($value)
 * @method static Builder<static>|Site whereName($value)
 * @method static Builder<static>|Site whereOrder($value)
 * @method static Builder<static>|Site whereStatus($value)
 * @method static Builder<static>|Site whereThemeId($value)
 * @method static Builder<static>|Site whereBlueprintId($value)
 * @method static Builder<static>|Site whereUpdatedAt($value)
 * @method static Builder<static>|Site whereUpdatedBy($value)
 * @method static Builder<static>|Site withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Site withWhereHasLanguage(int $language_id)
 * @method static Builder<static>|Site withoutTrashed()
 * @method null|static duplicate(mixed $attr = null)
 *
 * @mixin Model
 */
#[ObservedBy([SiteObserver::class])]
class Site extends Model implements Blueprintable, Defaultable, HasMedia, HasMediaContract, Statusable, Translatable, Userstampable
{
    use Cloneable;
    use HasBlueprint;
    use HasCapellMedia;

    /** @use HasDefault<Site> */
    use HasDefault;

    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    use HasJsonRelationships;
    use HasMetaData;

    /** @use HasStatus<Site> */
    use HasStatus;

    use HasTranslations;
    use HasUserstamps;
    use LogsActivity;
    use SoftDeletes;

    protected static string $factory = SiteFactory::class;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'admin',
        'default',
        'language_id',
        'meta',
        'name',
        'order',
        'theme_id',
        'blueprint_id',
        'status',
    ];

    /**
     * Relations on this model that should be cloned
     *
     * @var list<string>
     */
    protected array $cloneable_relations = [
        'media',
    ];

    /**
     * @return Collection<array-key, mixed>
     */
    public static function getOptions(string $key = 'id', string $value = 'name'): Collection
    {
        return self::query()->excludingPreview()->select([$value, $key])->ordered()->pluck($value, $key);
    }

    public static function totalSites(): int
    {
        return cache()
            ->driver('array')
            ->rememberForever(CacheEnum::TotalSites->value, fn (): int => self::query()->count());
    }

    public function getSiteDomainUrl(Language $language): string
    {
        $siteDomain = $this->siteDomains->firstWhere('language_id', $language->id);

        if ($siteDomain === null) {
            throw new SiteDomainNotFoundException('Site domain not found for language: ' . $language->id);
        }

        return $siteDomain->full_url;
    }

    /**
     * Returns all unique languages for this site: the main site language and all SiteDomain languages.
     *
     * @return Collection<int, Language>
     */
    public function getAllLanguages(): Collection
    {
        $this->siteDomains->loadMissing('language');

        $languages = collect([$this->language]);

        foreach ($this->siteDomains as $siteDomain) {
            if ($siteDomain->language !== null) {
                $languages->push($siteDomain->language);
            }
        }

        return $languages->unique(fn (Language $language): int => $language->id);
    }

    public function getHomePage(?Language $language = null): ?Page
    {
        return Page::getSiteHomePage($this, $language);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('site')
            ->logAll()
            ->logExcept([
                'updated_at',
                'created_at',
                'deleted_at',
                'created_by',
                'updated_by',
                'deleted_by',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Theme, $this> */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    /** @return BelongsTo<Language, $this> */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /** @return HasManyThrough<Language, Translation, $this> */
    public function languages(): HasManyThrough
    {
        return $this->hasManyThrough(
            Language::class,
            Translation::class,
            'translatable_id',
            'id',
            'id',
            'language_id',
        )
            ->where('translatable_type', $this->getMorphClass());
    }

    /** @return HasMany<SiteDomain, $this> */
    public function siteDomains(): HasMany
    {
        $model = $this->hasMany(SiteDomain::class);

        $model->chaperone();

        return $model;
    }

    /** @return HasOne<SiteDomain, $this> */
    public function siteDomain(): HasOne
    {
        return $this->defaultDomain();
    }

    /** @return HasOne<SiteDomain, $this> */
    public function defaultDomain(): HasOne
    {
        return $this->hasOne(SiteDomain::class)
            ->ofMany(
                ['default' => 'max'],
                /**
                 * @param  Builder<SiteDomain>  $query
                 */
                function (Builder $query): void {
                    $query->default();
                },
            );
    }

    /** @return HasMany<Page, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    /** @return HasMany<Layout, $this> */
    public function layouts(): HasMany
    {
        return $this->hasMany(Layout::class);
    }

    /** @return BelongsToJson<Site, $this> */
    public function related(): BelongsToJson
    {
        return $this->belongsToJson(self::class, 'meta->related');
    }

    public function getThemeColor(string $color): ?string
    {
        return $this->theme->colors[$color] ?? null;
    }

    public function getFirstPageByType(string $key): ?Page
    {
        return $this->pages()->whereRelation('blueprint', 'key', $key)->first();
    }

    public function hasDefaultDomain(): bool
    {
        return $this->siteDomains->contains(fn (SiteDomain $domain): bool => $domain->default);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(MediaCollectionEnum::Image->value)->singleFile();
        $this->addMediaCollection(MediaCollectionEnum::Logo->value)->singleFile();
        $this->addMediaCollection(MediaCollectionEnum::LogoInverted->value)->singleFile();
    }

    /** @return MorphOne<Media, self> */
    public function image(): MorphOne
    {
        return $this->morphOne(Media::class, 'model')
            ->where('collection_name', MediaCollectionEnum::Image->value);
    }

    /** @return MorphOne<Media, self> */
    public function logo(): MorphOne
    {
        return $this->morphOne(Media::class, 'model')
            ->where('collection_name', MediaCollectionEnum::Logo->value);
    }

    /** @return MorphOne<Media, self> */
    public function logoInverted(): MorphOne
    {
        return $this->morphOne(Media::class, 'model')
            ->where('collection_name', MediaCollectionEnum::LogoInverted->value);
    }

    /**
     * The attributes that should be cast to native blueprints.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'admin' => 'json',
            'default' => 'boolean',
            'meta' => 'json',
            'status' => 'boolean',
        ];
    }

    /**
     * Exclude AI-creator preview sites (meta.is_preview === true) from a query.
     *
     * Sites without the flag — meta null, key absent, or explicitly false — are
     * retained. Apply on public site resolution and listings so flagged preview
     * sites can never be served publicly or pollute site pickers.
     *
     * @param  Builder<Site>  $query
     */
    protected function scopeExcludingPreview(Builder $query): void
    {
        $query->where(function (Builder $builder): void {
            $builder->whereNull('meta->is_preview')
                ->orWhere('meta->is_preview', '!=', true);
        });
    }

    /**
     * @param  Builder<Site>  $query
     */
    protected function scopeOrdered(Builder $query, string $dir = 'asc'): void
    {
        $direction = strtolower($dir) === 'desc' ? 'desc' : 'asc';

        $query->orderBy($this->qualifyColumn('order'), $direction)
            ->orderBy($this->qualifyColumn('default'), 'desc')
            ->orderBy($this->qualifyColumn('name'), $direction);
    }
}
