<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Aimeos\Nestedset\Collection;
use Bkwld\Cloner\Cloneable;
use Capell\Core\Concerns\HasCapellMedia;
use Capell\Core\Contracts\Media\HasMediaContract;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Database\Factories\BlueprintFactory;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\CacheTime;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Exceptions\UnknownBlueprintSubjectException;
use Capell\Core\Models\Casts\BlueprintSubjectDataCast;
use Capell\Core\Models\Concerns\HasDefault;
use Capell\Core\Models\Concerns\HasMetaData;
use Capell\Core\Models\Concerns\HasPagePermissions;
use Capell\Core\Models\Concerns\HasStatus;
use Capell\Core\Models\Concerns\HasUserstamps;
use Capell\Core\Models\Contracts\Defaultable;
use Capell\Core\Models\Contracts\Statusable;
use Capell\Core\Models\Contracts\Userstampable;
use Capell\Core\Observers\BlueprintObserver;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\DB;
use Override;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;

/**
 * @property int $id
 * @property string $name
 * @property PageTypeData $type
 * @property string $key
 * @property string|null $group
 * @property array<array-key, mixed>|null $meta
 * @property array<array-key, mixed>|null $admin
 * @property string|null $component
 * @property string|null $component_item
 * @property bool|null $is_livewire
 * @property string|null $view_file
 * @property int $order
 * @property bool $default
 * @property bool $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read MediaCollection<int, Media> $media
 * @property-read int|null $media_count
 * @property-read AuthenticatableUser|null $creator
 * @property-read AuthenticatableUser|null $destroyer
 * @property-read AuthenticatableUser|null $editor
 * @property-read CacheTime|null $cache_time
 * @property-read ContentStructure|null $content_structure
 * @property-read Collection<int, Page> $pages
 * @property-read int|null $pages_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Site> $sites
 * @property-read int|null $sites_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Theme> $themes
 * @property-read int|null $themes_count
 *
 * @method static Builder<static>|Blueprint accessible()
 * @method static Builder<static>|Blueprint adminResource(string $group)
 * @method static Builder<static>|Blueprint default(bool $default = true)
 * @method static Builder<static>|Blueprint disabled()
 * @method static Builder<static>|Blueprint enabled()
 * @method static BlueprintFactory factory(?int $count = null, array<string, mixed> $state = [])
 * @method static Builder<static>|Blueprint hiddenSystemGroup()
 * @method static Builder<static>|Blueprint listable(bool $sitemap = false)
 * @method static Builder<static>|Blueprint navigationType()
 * @method static Builder<static>|Blueprint newModelQuery()
 * @method static Builder<static>|Blueprint newQuery()
 * @method static Builder<static>|Blueprint nonDefault()
 * @method static Builder<static>|Blueprint onlyTrashed()
 * @method static Builder<static>|Blueprint ordered()
 * @method static Builder<static>|Blueprint pageType()
 * @method static Builder<static>|Blueprint query()
 * @method static Builder<static>|Blueprint siteType()
 * @method static Builder<static>|Blueprint status(bool $enabled)
 * @method static Builder<static>|Blueprint themeType()
 * @method static Builder<static>|Blueprint type(BlueprintSubjectEnum|string $type)
 * @method static Builder<static>|Blueprint visible()
 * @method static Builder<static>|Blueprint whereAdmin(array<string, mixed> $value)
 * @method static Builder<static>|Blueprint whereCreatedAt(CarbonImmutable|string|null $value)
 * @method static Builder<static>|Blueprint whereCreatedBy(int|null $value)
 * @method static Builder<static>|Blueprint whereDefault(bool $value)
 * @method static Builder<static>|Blueprint whereDeletedAt(CarbonImmutable|string|null $value)
 * @method static Builder<static>|Blueprint whereDeletedBy(int|null $value)
 * @method static Builder<static>|Blueprint whereGroup(string|null $value)
 * @method static Builder<static>|Blueprint whereId(int $value)
 * @method static Builder<static>|Blueprint whereKey(string $value)
 * @method static Builder<static>|Blueprint whereMeta(array<string, mixed> $value)
 * @method static Builder<static>|Blueprint whereName(string $value)
 * @method static Builder<static>|Blueprint whereOrder(int $value)
 * @method static Builder<static>|Blueprint whereStatus(bool $value)
 * @method static Builder<static>|Blueprint whereType(BlueprintSubjectEnum|string $value)
 * @method static Builder<static>|Blueprint whereUpdatedAt(CarbonImmutable|string|null $value)
 * @method static Builder<static>|Blueprint whereUpdatedBy(int|null $value)
 * @method static Builder<static>|Blueprint withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Blueprint withoutTrashed()
 * @method null|static duplicate(mixed $attr = null)
 *
 * @mixin Model
 */
#[ObservedBy(BlueprintObserver::class)]
class Blueprint extends Model implements Defaultable, HasMedia, HasMediaContract, Statusable, Userstampable
{
    use Cloneable;
    use HasCapellMedia;

    /** @use HasDefault<Blueprint> */
    use HasDefault;

    /** @use HasFactory<BlueprintFactory> */
    use HasFactory;

    use HasMetaData;
    use HasPagePermissions;

    /** @use HasStatus<Blueprint> */
    use HasStatus;

    use HasUserstamps;
    use SoftDeletes;

    /**
     * Navigation structures are stored as blueprints but are not a subject.
     *
     * A subject is a model that *carries* a blueprint (a Page has one, a Site
     * has one). A navigation record has no such owner — the blueprint row is the
     * record. It therefore never appears in
     * {@see BlueprintSubjectRegistry}, and the type cast
     * routes it around subject resolution rather than failing to resolve it.
     */
    public const NAVIGATION_TYPE = 'navigation';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'admin',
        'component',
        'component_item',
        'default',
        'group',
        'is_livewire',
        'key',
        'meta',
        'name',
        'order',
        'status',
        'type',
        'view_file',
    ];

    protected static string $factory = BlueprintFactory::class;

    /**
     * @return array<string, string>
     */
    public static function getGroups(): array
    {
        return DB::table('blueprints')
            ->select('group')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('group')
            ->orderBy('blueprints.group')
            ->whereNotNull('group')
            ->pluck('aggregate', 'group')
            ->mapWithKeys(static fn (int|string $count, string $group): array => [
                $group => sprintf('%s (%d)', $group, $count),
            ])
            ->all();
    }

    /**
     * @return array<string>
     */
    public static function getTypes(): array
    {
        return DB::table('blueprints')
            ->select('type')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('type')
            ->orderBy('type')
            ->pluck('aggregate', 'type')
            ->toArray();
    }

    public static function missingDefaultTypes(): bool
    {
        $blueprints = self::getTypes();

        if ($blueprints === []) {
            return true;
        }

        // Every registered subject must have a default blueprint; package-owned
        // subjects participate without requiring a core enum change.
        return ! collect(resolve(BlueprintSubjectRegistry::class)->keys())
            ->every(static fn (string $subject): bool => array_key_exists($subject, $blueprints));
    }

    /**
     * Blueprint types that are structural records rather than model subjects.
     *
     * @return list<string>
     */
    public static function reservedInternalTypes(): array
    {
        return [self::NAVIGATION_TYPE];
    }

    public static function isReservedInternalType(string $type): bool
    {
        return in_array($type, self::reservedInternalTypes(), true);
    }

    public function isSystem(): bool
    {
        return $this->group === BlueprintGroupEnum::System->value;
    }

    /** @return HasMany<Page, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** @return HasMany<Theme, $this> */
    public function themes(): HasMany
    {
        return $this->hasMany(Theme::class);
    }

    /**
     * @param  Builder<Blueprint>  $query
     */
    protected function scopeAccessible(Builder $query): void
    {
        // A blueprint is accessible unless meta->accessible is explicitly false.
        // orWhereJsonDoesntContainKey handles the absent-key case explicitly:
        // on MySQL/MariaDB, JSON_CONTAINS(meta, 'false', '$.accessible') returns
        // NULL when the key is absent, so orWhereJsonDoesntContain alone would
        // exclude those rows (and silently 404 the seeded home page), while
        // SQLite includes them — masking the bug in the sqlite-backed test suite.
        $query->where(
            fn (Builder $query): Builder => $query
                ->whereNull($this->qualifyColumn('meta'))
                ->orWhereJsonDoesntContainKey($this->qualifyColumn('meta->accessible'))
                ->orWhereJsonDoesntContain($this->qualifyColumn('meta->accessible'), false),
        );
    }

    /**
     * @param  Builder<Blueprint>  $query
     */
    protected function scopeListable(Builder $query): void
    {
        // A blueprint is listable unless meta->listable is explicitly false.
        // orWhereJsonDoesntContainKey covers the absent-key case, which MySQL's
        // JSON_CONTAINS otherwise evaluates to NULL (excluding the row); see
        // scopeAccessible for the full cross-database explanation.
        $query->where(
            fn (Builder $query): Builder => $query
                ->whereNull($this->qualifyColumn('meta'))
                ->orWhereJsonDoesntContainKey($this->qualifyColumn('meta->listable'))
                ->orWhereJsonDoesntContain($this->qualifyColumn('meta->listable'), false),
        );
    }

    /**
     * @param  Builder<Blueprint>  $query
     */
    protected function scopeAdminResource(Builder $query, string $group): void
    {
        if ($group === 'page' || $group === 'default') {
            $query->where(
                fn (Builder $query): Builder => $query
                    ->whereNull('group')
                    ->orWhereIn('group', [
                        BlueprintGroupEnum::Default->value,
                        BlueprintGroupEnum::System->value,
                    ]),
            );
        } else {
            $query->where('group', $group);
        }
    }

    /**
     * @param  Builder<Blueprint>  $query
     */
    protected function scopeHiddenSystemGroup(Builder $query): void
    {
        $query->where(
            fn (Builder $query): Builder => $query
                ->whereNull($this->qualifyColumn('group'))
                ->orWhere($this->qualifyColumn('group'), '!=', BlueprintGroupEnum::System->value),
        );
    }

    /**
     * @param  Builder<Blueprint>  $query
     */
    protected function scopeOrdered(Builder $query): void
    {
        $query->orderBy($this->qualifyColumn('order'))
            ->orderBy($this->qualifyColumn('default'), 'desc')
            ->orderBy($this->qualifyColumn('name'));
    }

    /**
     * @param  Builder<Blueprint>  $query
     */
    protected function scopePageType(Builder $query): void
    {
        $query->type(BlueprintSubjectEnum::Page->value);
    }

    /**
     * @param  Builder<Blueprint>  $query
     */
    protected function scopeSiteType(Builder $query): void
    {
        $query->type(BlueprintSubjectEnum::Site->value);
    }

    /**
     * @param  Builder<Blueprint>  $query
     */
    protected function scopeThemeType(Builder $query): void
    {
        $query->type(BlueprintSubjectEnum::Theme->value);
    }

    /**
     * @param  Builder<Blueprint>  $query
     */
    protected function scopeNavigationType(Builder $query): void
    {
        $query->where('type', self::NAVIGATION_TYPE);
    }

    /**
     * @param  Builder<Blueprint>  $query
     */
    protected function scopeVisible(Builder $query): void
    {
        $query->where(
            fn (Builder $query): Builder => $query
                ->whereNull($this->qualifyColumn('meta'))
                ->orWhereJsonDoesntContain($this->qualifyColumn('meta->hidden'), true),
        );
    }

    /**
     * Filter to one blueprint subject.
     *
     * The key is resolved through {@see BlueprintSubjectRegistry} rather than
     * used raw, so a typo or an uninstalled package fails loudly here instead of
     * silently returning an empty result set that reads as "no blueprints yet".
     *
     * @param  Builder<Blueprint>  $query
     *
     * @throws UnknownBlueprintSubjectException when no package registered the subject.
     */
    protected function scopeType(Builder $query, BlueprintSubjectEnum|string $type): void
    {
        $subject = resolve(BlueprintSubjectRegistry::class)->descriptor($type);

        $query->where('type', $subject->key);
    }

    /** @return Attribute<ContentStructure|null, never> */
    protected function contentStructure(): Attribute
    {
        return Attribute::make(get: function (): ?ContentStructure {
            if (! isset($this->meta['content_structure'])) {
                return null;
            }

            return ContentStructure::from($this->meta['content_structure'] ?? '');
        });
    }

    /** @return Attribute<CacheTime|null, never> */
    protected function cacheTime(): Attribute
    {
        return Attribute::make(get: function (): ?CacheTime {
            if (! isset($this->meta['cache_time'])) {
                return null;
            }

            return CacheTime::from($this->meta['cache_time'] ?? '');
        });
    }

    /** @return Attribute<never, mixed> */
    protected function meta(): Attribute
    {
        return Attribute::make(set: function (mixed $value): array {
            if (is_string($value)) {
                $value = json_decode($value, true);
            }

            if (! is_array($value)) {
                return ['meta' => $value === null ? null : json_encode($value)];
            }

            $attributes = [];

            foreach (['component', 'component_item', 'view_file'] as $column) {
                if (array_key_exists($column, $value)) {
                    $attributes[$column] = $this->nullableComponentString($value[$column]);
                    unset($value[$column]);
                }
            }

            if (array_key_exists('livewire', $value)) {
                $attributes['is_livewire'] = (bool) $value['livewire'];
                unset($value['livewire']);
            }

            $attributes['meta'] = $value === [] ? null : json_encode($value);

            return $attributes;
        });
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'admin' => 'json',
            'default' => 'boolean',
            'is_livewire' => 'boolean',
            'meta' => 'json',
            'status' => 'boolean',
            'type' => BlueprintSubjectDataCast::class,
        ];
    }

    private function nullableComponentString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
