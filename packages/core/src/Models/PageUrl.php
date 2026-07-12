<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Awobaz\Compoships\Compoships;
use Capell\Core\Actions\DiagnosePageUrlSiteDomainAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Database\Factories\UrlFactory;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Exceptions\UrlMissingSiteDomainException;
use Capell\Core\Models\Concerns\HasStatus;
use Capell\Core\Models\Concerns\HasUserstamps;
use Capell\Core\Models\Contracts\Statusable;
use Capell\Core\Models\Contracts\Userstampable;
use Capell\Core\Observers\PageUrlObserver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Override;

/**
 * @property int $id
 * @property int $site_id
 * @property int $language_id
 * @property int|null $pageable_id
 * @property string|null $pageable_type
 * @property string $url
 * @property string|null $target_url
 * @property RedirectStatusCodeEnum $status_code
 * @property bool $is_manual
 * @property int $hit_count
 * @property CarbonImmutable|null $last_hit_at
 * @property string|null $notes
 * @property UrlTypeEnum|null $type
 * @property bool $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read AuthenticatableUser|null $creator
 * @property-read AuthenticatableUser|null $destroyer
 * @property-read AuthenticatableUser|null $editor
 * @property-read string $full_url
 * @property-read Language $language
 * @property-read Pageable<Model> $pageable
 * @property-read Site $site
 * @property-read SiteDomain $siteDomain
 * @property-read RedirectHealthSnapshot|null $redirectHealthSnapshot
 * @property-read Translation|null $translation
 *
 * @method static Builder<static>|PageUrl disabled()
 * @method static Builder<static>|PageUrl enabled()
 * @method static UrlFactory factory($count = null, $state = [])
 * @method static Builder<static>|PageUrl newModelQuery()
 * @method static Builder<static>|PageUrl newQuery()
 * @method static Builder<static>|PageUrl onlyTrashed()
 * @method static Builder<static>|PageUrl ordered()
 * @method static Builder<static>|PageUrl query()
 * @method static Builder<static>|PageUrl status(bool $enabled)
 * @method static Builder<static>|PageUrl whereCreatedAt($value)
 * @method static Builder<static>|PageUrl whereCreatedBy($value)
 * @method static Builder<static>|PageUrl whereDeletedAt($value)
 * @method static Builder<static>|PageUrl whereDeletedBy($value)
 * @method static Builder<static>|PageUrl whereId($value)
 * @method static Builder<static>|PageUrl whereLanguageId($value)
 * @method static Builder<static>|PageUrl wherePageId($value)
 * @method static Builder<static>|PageUrl whereSiteId($value)
 * @method static Builder<static>|PageUrl whereStatus($value)
 * @method static Builder<static>|PageUrl whereType($value)
 * @method static Builder<static>|PageUrl whereUpdatedAt($value)
 * @method static Builder<static>|PageUrl whereUpdatedBy($value)
 * @method static Builder<static>|PageUrl whereUrl($value)
 * @method static Builder<static>|PageUrl withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|PageUrl withoutTrashed()
 *
 * @mixin Model
 */
#[ObservedBy(PageUrlObserver::class)]
class PageUrl extends Model implements Statusable, Userstampable
{
    use Compoships;

    /** @use HasFactory<UrlFactory> */
    use HasFactory;

    /** @use HasStatus<PageUrl> */
    use HasStatus;

    use HasUserstamps;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'language_id',
        'site_id',
        'status',
        'type',
        'url',
        'target_url',
        'status_code',
        'is_manual',
        'hit_count',
        'last_hit_at',
        'notes',
        'pageable_type',
        'pageable_id',
    ];

    protected static string $factory = UrlFactory::class;

    public static function loadByUrl(string $url, SiteDomain $siteDomain, ?Language $language = null): ?self
    {
        if ($url === '' || $url === '0') {
            return null;
        }

        $languageId = $language instanceof Language ? $language->id : $siteDomain->language_id;

        return self::query()
            ->where('url', $url)
            ->where('site_id', $siteDomain->site_id)
            ->where('language_id', $languageId)
            ->first();
    }

    /**
     * @return Collection<int, string>
     */
    public static function volatileUrls(): Collection
    {
        return self::query()->select('url')
            ->whereHas(
                'pageable',
                fn (Builder $query) => $query->whereHas(
                    'type',
                    fn (BuilderContract $query): BuilderContract => $query->where(
                        'blueprints.meta->cache_frequency',
                        'always',
                    ),
                ),
            )
            ->pluck('url');
    }

    public function hasTargetUrl(): bool
    {
        return $this->target_url !== null && $this->target_url !== '';
    }

    public function isManualRedirect(): bool
    {
        return $this->is_manual && $this->isRedirect();
    }

    public function isRedirect(): bool
    {
        return $this->type === UrlTypeEnum::Redirect;
    }

    /** @return BelongsTo<Language, $this> */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /** @return MorphTo<Model, $this> */
    public function pageable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<SiteDomain, $this> */
    public function siteDomain(): BelongsTo
    {
        return $this->belongsTo(
            SiteDomain::class,
            ['site_id', 'language_id'],
            ['site_id', 'language_id'],
        );
    }

    /** @return HasOne<RedirectHealthSnapshot, $this> */
    public function redirectHealthSnapshot(): HasOne
    {
        return $this->hasOne(RedirectHealthSnapshot::class);
    }

    /** @return HasOne<Translation, $this> */
    public function translation(): HasOne
    {
        return $this->hasOne(
            Translation::class,
            ['translatable_id', 'translatable_type', 'language_id'],
            ['pageable_id', 'pageable_type', 'language_id'],
        );
    }

    /**
     * @throws UrlMissingSiteDomainException
     */
    public function fullUrl(): string
    {
        $loadedSiteDomain = $this->relationLoaded('siteDomain')
            ? $this->getRelation('siteDomain')
            : null;

        $loadedActiveSiteDomain = $loadedSiteDomain instanceof SiteDomain && $loadedSiteDomain->status
            ? $loadedSiteDomain
            : null;

        $siteDomain = $loadedActiveSiteDomain?->default
            ? $loadedActiveSiteDomain
            : $this->siteDomain()
                ->where('site_domains.status', true)
                ->where('site_domains.default', true)
                ->first();

        $siteDomain ??= $loadedActiveSiteDomain
            ?? $this->siteDomain()->where('site_domains.status', true)->first();

        if (! $siteDomain instanceof SiteDomain) {
            $this->logMissingSiteDomainDiagnostic();

            throw new UrlMissingSiteDomainException(
                sprintf(
                    'Site domain not found for page ID %d, site ID %d, and language ID %d',
                    $this->pageable_id,
                    $this->site_id,
                    $this->language_id,
                ),
            );
        }

        return $this->fullUrlFor($siteDomain, $this->url);
    }

    /**
     * @param  Builder<PageUrl>  $query
     */
    protected function scopeAutoRedirects(Builder $query): void
    {
        $query->where('is_manual', false)->where('type', UrlTypeEnum::Redirect);
    }

    /**
     * @param  Builder<PageUrl>  $query
     */
    protected function scopeManualRedirects(Builder $query): void
    {
        $query->where('is_manual', true)->where('type', UrlTypeEnum::Redirect);
    }

    /**
     * @param  Builder<PageUrl>  $query
     */
    protected function scopeOrdered(Builder $query): void
    {
        $query->orderBy(
            Language::query()->select('order')
                ->whereColumn('languages.id', 'page_urls.language_id')
                ->limit(1),
        );
    }

    /**
     * @param  Builder<PageUrl>  $query
     */
    protected function scopeRedirects(Builder $query): void
    {
        $query->where('type', UrlTypeEnum::Redirect);
    }

    /**
     * @throws UrlMissingSiteDomainException
     */
    protected function getFullUrlAttribute(): string
    {
        return $this->fullUrl();
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
            'is_manual' => 'boolean',
            'status' => 'boolean',
            'status_code' => RedirectStatusCodeEnum::class,
            'last_hit_at' => 'datetime',
            'type' => UrlTypeEnum::class,
        ];
    }

    private function fullUrlFor(SiteDomain $siteDomain, string $urlPath): string
    {
        $url = $siteDomain->full_url . $urlPath;

        if (str_ends_with($url, '/*')) {
            $url = mb_substr($url, 0, -2);
        }

        return mb_rtrim($url, '/');
    }

    private function logMissingSiteDomainDiagnostic(): void
    {
        if (config('capell.debug.relationship_diagnostics') !== true) {
            return;
        }

        Log::warning(
            'Capell PageUrl full_url could not resolve an active site domain.',
            DiagnosePageUrlSiteDomainAction::run($this)->toLogContext(),
        );
    }
}
