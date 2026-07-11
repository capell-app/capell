<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Bkwld\Cloner\Cloneable;
use Capell\Core\Database\Factories\LanguageFactory;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Concerns\HasDefault;
use Capell\Core\Models\Concerns\HasMetaData;
use Capell\Core\Models\Concerns\HasStatus;
use Capell\Core\Models\Concerns\HasUserstamps;
use Capell\Core\Models\Contracts\Defaultable;
use Capell\Core\Models\Contracts\Statusable;
use Capell\Core\Models\Contracts\Userstampable;
use Capell\Core\Observers\LanguageObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Collection;
use Override;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $flag
 * @property array<array-key, mixed>|null $meta
 * @property array<array-key, mixed>|null $admin
 * @property string|null $locale
 * @property int $order
 * @property bool $default
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
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Site> $sites
 * @property-read int|null $sites_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Site> $sitesLanguage
 * @property-read int|null $sites_language_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Translation> $translations
 * @property-read int|null $translations_count
 *
 * @method static Builder<static>|Language default(bool $default = true)
 * @method static Builder<static>|Language disabled()
 * @method static Builder<static>|Language enabled()
 * @method static LanguageFactory factory($count = null, $state = [])
 * @method static Builder<static>|Language locales()
 * @method static Builder<static>|Language newModelQuery()
 * @method static Builder<static>|Language newQuery()
 * @method static Builder<static>|Language nonDefault()
 * @method static Builder<static>|Language onlyTrashed()
 * @method static Builder<static>|Language ordered(string $dir = 'asc')
 * @method static Builder<static>|Language query()
 * @method static Builder<static>|Language status(bool $enabled)
 * @method static Builder<static>|Language whereAdmin($value)
 * @method static Builder<static>|Language whereCode($value)
 * @method static Builder<static>|Language whereCreatedAt($value)
 * @method static Builder<static>|Language whereCreatedBy($value)
 * @method static Builder<static>|Language whereDefault($value)
 * @method static Builder<static>|Language whereDeletedAt($value)
 * @method static Builder<static>|Language whereDeletedBy($value)
 * @method static Builder<static>|Language whereFlag($value)
 * @method static Builder<static>|Language whereId($value)
 * @method static Builder<static>|Language whereLocale($value)
 * @method static Builder<static>|Language whereMeta($value)
 * @method static Builder<static>|Language whereName($value)
 * @method static Builder<static>|Language whereOrder($value)
 * @method static Builder<static>|Language whereStatus($value)
 * @method static Builder<static>|Language whereUpdatedAt($value)
 * @method static Builder<static>|Language whereUpdatedBy($value)
 * @method static Builder<static>|Language withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Language withoutTrashed()
 * @method null|static duplicate(mixed $attr = null)
 *
 * @mixin Model
 */
#[ObservedBy(LanguageObserver::class)]
class Language extends Model implements Defaultable, Statusable, Userstampable
{
    use Cloneable;

    /** @use HasDefault<Language> */
    use HasDefault;

    /** @use HasFactory<LanguageFactory> */
    use HasFactory;

    use HasMetaData;

    /** @use HasStatus<Language> */
    use HasStatus;

    use HasUserstamps;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'admin',
        'code',
        'default',
        'flag',
        'locale',
        'meta',
        'name',
        'order',
        'status',
    ];

    protected static string $factory = LanguageFactory::class;

    /** @return array<int, string> */
    public static function getLanguageLocales(): array
    {
        return CapellCore::rememberCache(
            CacheEnum::LanguageLocales->value,
            function (): array {
                /** @var class-string<Language> $model */
                $model = Language::class;

                return $model::query()
                    ->select('code')
                    ->ordered()
                    ->pluck('code')
                    ->toArray();
            },
        );
    }

    /** @return Collection<int|string, string> */
    public static function getOptions(string $key = 'id', string $value = 'name'): Collection
    {
        return self::query()->select([$value, $key])->ordered()->pluck($value, $key);
    }

    /** @return HasManyThrough<Site, SiteDomain, $this> */
    public function sites(): HasManyThrough
    {
        return $this->hasManyThrough(Site::class, SiteDomain::class, 'language_id', 'id', 'id', 'site_id');
    }

    /** @return HasMany<Site, $this> */
    public function sitesLanguage(): HasMany
    {
        return $this->hasMany(Site::class, 'language_id');
    }

    /** @return HasMany<Translation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }

    /**
     * Get all sites for this language, via site language or domain linkage.
     *
     * @return Collection<int, Site>
     */
    public function allSites(): Collection
    {
        return $this->sites
            ->merge($this->sitesLanguage)
            ->unique('id')
            ->values();
    }

    /** @param Builder<Language> $query */
    protected function scopeLocales(Builder $query): void
    {
        $query->enabled()->ordered()->pluck('locale', 'id');
    }

    /** @param Builder<Language> $query */
    protected function scopeOrdered(Builder $query, string $dir = 'asc'): void
    {
        $sortDirection = $dir === 'desc' ? 'desc' : 'asc';

        $query->orderBy($this->qualifyColumn('order'), $sortDirection)
            ->orderBy($this->qualifyColumn('default'), $sortDirection === 'asc' ? 'desc' : 'asc')
            ->orderBy($this->qualifyColumn('name'), $sortDirection);
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
            'meta' => 'json',
            'admin' => 'json',
            'default' => 'boolean',
            'status' => 'boolean',
        ];
    }
}
