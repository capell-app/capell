<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Awobaz\Compoships\Compoships;
use Bkwld\Cloner\Cloneable;
use Capell\Core\Database\Factories\SiteDomainFactory;
use Capell\Core\Models\Concerns\HasDefault;
use Capell\Core\Models\Concerns\HasStatus;
use Capell\Core\Models\Concerns\HasUserstamps;
use Capell\Core\Models\Contracts\Defaultable;
use Capell\Core\Models\Contracts\Statusable;
use Capell\Core\Models\Contracts\Userstampable;
use Capell\Core\Observers\SiteDomainObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Override;

/**
 * @property int $id
 * @property int $site_id
 * @property int|null $language_id
 * @property string|null $domain
 * @property string|null $scheme
 * @property string|null $path
 * @property bool $status
 * @property bool $default
 * @property CarbonImmutable|null $deleted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AuthenticatableUser|null $creator
 * @property-read AuthenticatableUser|null $destroyer
 * @property-read AuthenticatableUser|null $editor
 * @property-read string $full_url
 * @property-read string $root_url
 * @property-read Language|null $language
 * @property-read string $name
 * @property-read Collection<int, PageUrl> $pageUrls
 * @property-read int|null $page_urls_count
 * @property-read Site $site
 * @property-read mixed $url
 *
 * @method static Builder<static>|SiteDomain default(bool $default = true)
 * @method static Builder<static>|SiteDomain disabled()
 * @method static Builder<static>|SiteDomain enabled()
 * @method static SiteDomainFactory factory($count = null, $state = [])
 * @method static Builder<static>|SiteDomain newModelQuery()
 * @method static Builder<static>|SiteDomain newQuery()
 * @method static Builder<static>|SiteDomain nonDefault()
 * @method static Builder<static>|SiteDomain onlyTrashed()
 * @method static Builder<static>|SiteDomain query()
 * @method static Builder<static>|SiteDomain status(bool $enabled)
 * @method static Builder<static>|SiteDomain whereCreatedAt($value)
 * @method static Builder<static>|SiteDomain whereCreatedBy($value)
 * @method static Builder<static>|SiteDomain whereDefault($value)
 * @method static Builder<static>|SiteDomain whereDeletedAt($value)
 * @method static Builder<static>|SiteDomain whereDeletedBy($value)
 * @method static Builder<static>|SiteDomain whereDomain($value)
 * @method static Builder<static>|SiteDomain whereId($value)
 * @method static Builder<static>|SiteDomain whereLanguageId($value)
 * @method static Builder<static>|SiteDomain wherePath($value)
 * @method static Builder<static>|SiteDomain whereScheme($value)
 * @method static Builder<static>|SiteDomain whereSiteId($value)
 * @method static Builder<static>|SiteDomain whereStatus($value)
 * @method static Builder<static>|SiteDomain whereUpdatedAt($value)
 * @method static Builder<static>|SiteDomain whereUpdatedBy($value)
 * @method static Builder<static>|SiteDomain withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|SiteDomain withoutTrashed()
 * @method null|static duplicate(mixed $attr = null)
 *
 * @mixin Model
 */
#[ObservedBy(SiteDomainObserver::class)]
class SiteDomain extends Model implements Defaultable, Statusable, Userstampable
{
    use Cloneable;
    use Compoships;

    /** @use HasDefault<self> */
    use HasDefault;

    /** @use HasFactory<SiteDomainFactory> */
    use HasFactory;

    /** @use HasStatus<self> */
    use HasStatus;

    use HasUserstamps;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'default',
        'domain',
        'language_id',
        'path',
        'scheme',
        'site_id',
        'status',
    ];

    protected static string $factory = SiteDomainFactory::class;

    public function getDomainKey(): string
    {
        $keys = [
            is_string($this->scheme) && $this->scheme !== '' ? $this->scheme : 'https',
            str_replace('.', '-', $this->getResolvedDomain()),
        ];

        if (is_string($this->path) && $this->path !== '') {
            $keys[] = str_replace('/', '.', mb_trim($this->path, '/'));
        }

        return implode('-', $keys);
    }

    /** @return BelongsTo<Language, $this> */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /** @return HasMany<PageUrl, $this> */
    public function pageUrls(): HasMany
    {
        return $this->hasMany(PageUrl::class, ['site_id', 'language_id'], ['site_id', 'language_id']);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    protected function getFullUrlAttribute(): string
    {
        return $this->root_url . $this->path;
    }

    protected function getRootUrlAttribute(): string
    {
        return $this->scheme . '://' . $this->getResolvedDomain();
    }

    protected function getNameAttribute(): string
    {
        return $this->getResolvedDomain() . $this->url;
    }

    protected function getSchemeAttribute(): ?string
    {
        $scheme = $this->attributes['scheme'] ?? config('capell-frontend.default_scheme');

        return is_string($scheme) && $scheme !== '' ? $scheme : request()->getScheme();
    }

    protected function getUrlAttribute(): string
    {
        return $this->path ?? '/';
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
            'default' => 'boolean',
            'status' => 'boolean',
        ];
    }

    private function getResolvedDomain(): string
    {
        if ($this->domain !== null && $this->domain !== '') {
            return $this->domain;
        }

        $appUrlHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($appUrlHost) && $appUrlHost !== '') {
            return $appUrlHost;
        }

        return 'path-only';
    }
}
