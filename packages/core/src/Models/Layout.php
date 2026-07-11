<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Aimeos\Nestedset\Collection;
use Bkwld\Cloner\Cloneable;
use Capell\Core\Concerns\HasCapellMedia;
use Capell\Core\Contracts\Media\HasMediaContract;
use Capell\Core\Database\Factories\LayoutFactory;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Concerns\ExtensibleModel;
use Capell\Core\Models\Concerns\HasDefault;
use Capell\Core\Models\Concerns\HasMetaData;
use Capell\Core\Models\Concerns\HasStatus;
use Capell\Core\Models\Concerns\HasUserstamps;
use Capell\Core\Models\Contracts\Defaultable;
use Capell\Core\Models\Contracts\Statusable;
use Capell\Core\Models\Contracts\Userstampable;
use Capell\Core\Observers\LayoutObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\DB;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;
use Staudenmeir\EloquentJsonRelations\HasJsonRelationships;

/**
 * @property int $id
 * @property string $name
 * @property int|null $theme_id
 * @property int|null $site_id
 * @property string $key
 * @property string|null $group
 * @property array<array-key, mixed>|null $meta
 * @property array<array-key, mixed>|null $admin
 * @property array<int, array<string, mixed>>|null $containers
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
 * @property-read MediaCollection<int, Media> $media
 * @property-read int|null $media_count
 * @property-read AuthenticatableUser|null $creator
 * @property-read AuthenticatableUser|null $destroyer
 * @property-read AuthenticatableUser|null $editor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Activity> $activities
 * @property-read int|null $activities_count
 * @property-read Collection<int, Page> $pages
 * @property-read int|null $pages_count
 * @property-read Site|null $site
 * @property-read Theme|null $theme
 * @property array<int, string>|null $elements
 * @property-read array<int, string> $widgets
 *
 * @method static Builder<static>|Layout default(bool $default = true)
 * @method static Builder<static>|Layout disabled()
 * @method static Builder<static>|Layout enabled()
 * @method static LayoutFactory factory($count = null, $state = [])
 * @method static Builder<static>|Layout newModelQuery()
 * @method static Builder<static>|Layout newQuery()
 * @method static Builder<static>|Layout nonDefault()
 * @method static Builder<static>|Layout onlyTrashed()
 * @method static Builder<static>|Layout ordered(string $dir = 'asc')
 * @method static Builder<static>|Layout query()
 * @method static Builder<static>|Layout status(bool $enabled)
 * @method static Builder<static>|Layout whereAdmin($value)
 * @method static Builder<static>|Layout whereCreatedAt($value)
 * @method static Builder<static>|Layout whereCreatedBy($value)
 * @method static Builder<static>|Layout whereDefault($value)
 * @method static Builder<static>|Layout whereDeletedAt($value)
 * @method static Builder<static>|Layout whereDeletedBy($value)
 * @method static Builder<static>|Layout whereGroup($value)
 * @method static Builder<static>|Layout whereId($value)
 * @method static Builder<static>|Layout whereKey($value)
 * @method static Builder<static>|Layout whereMeta($value)
 * @method static Builder<static>|Layout whereName($value)
 * @method static Builder<static>|Layout whereOrder($value)
 * @method static Builder<static>|Layout whereSiteId($value)
 * @method static Builder<static>|Layout whereStatus($value)
 * @method static Builder<static>|Layout whereThemeId($value)
 * @method static Builder<static>|Layout whereUpdatedAt($value)
 * @method static Builder<static>|Layout whereUpdatedBy($value)
 * @method static Builder<static>|Layout withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Layout withoutTrashed()
 * @method null|static duplicate(mixed $attr = null)
 *
 * @mixin Model
 */
#[ObservedBy(LayoutObserver::class)]
class Layout extends Model implements Defaultable, HasMedia, HasMediaContract, Statusable, Userstampable
{
    use Cloneable;
    use ExtensibleModel;
    use HasCapellMedia;

    /** @use HasDefault<self> */
    use HasDefault;

    /** @use HasFactory<LayoutFactory> */
    use HasFactory;

    use HasJsonRelationships;
    use HasMetaData;
    use HasRelationships;

    /** @use HasStatus<self> */
    use HasStatus;

    use HasUserstamps;
    use LogsActivity;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'theme_id',
        'site_id',
        'key',
        'group',
        'meta',
        'admin',
        'containers',
        'order',
        'default',
        'status',
        'elements',
    ];

    protected static string $factory = LayoutFactory::class;

    /**
     * @return array<string, string>
     */
    public static function getGroups(): array
    {
        $countSql = '(SELECT COUNT(*) FROM `layouts` `c2` WHERE `c2`.`group` = `layouts`.`group`)';

        if (DB::getDriverName() === 'sqlite') {
            $labelColumnSql = DB::raw(sprintf("`group` || ' (' || %s || ')' AS label", $countSql));
        } else {
            $labelColumnSql = DB::raw(sprintf("CONCAT(`group`, ' (', %s, ')') AS label", $countSql));
        }

        return DB::table('layouts')
            ->select(['group', $labelColumnSql])
            ->groupBy('group')
            ->orderBy('group')
            ->whereNotNull('group')
            ->pluck('label', 'group')
            ->toArray();
    }

    /** @return BelongsTo<Theme, $this> */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<Page, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(MediaCollectionEnum::Image->value)->singleFile();
    }

    /** @return MorphOne<Media, self> */
    public function image(): MorphOne
    {
        return $this->morphOne(Media::class, 'model')
            ->where('collection_name', MediaCollectionEnum::Image->value);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('layout')
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

    /**
     * @param  Builder<self>  $query
     */
    protected function scopeOrdered(Builder $query, string $dir = 'asc'): void
    {
        $sortDirection = $dir === 'desc' ? 'desc' : 'asc';

        $query->orderBy($this->qualifyColumn('order'), $sortDirection)
            ->orderBy($this->qualifyColumn('name'), $sortDirection);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'admin' => 'json',
            'containers' => 'array',
            'default' => 'boolean',
            'meta' => 'json',
            'status' => 'boolean',
            'elements' => 'array',
        ];
    }

    /**
     * @return Attribute<list<non-empty-string>, never>
     */
    protected function widgets(): Attribute
    {
        return Attribute::get(function (): array {
            $containers = $this->containers;

            if (! is_array($containers)) {
                return [];
            }

            $widgets = [];

            foreach ($containers as $container) {
                if (! is_array($container)) {
                    continue;
                }

                $containerWidgets = $container['widgets'] ?? [];

                if (! is_array($containerWidgets)) {
                    continue;
                }

                foreach ($containerWidgets as $widget) {
                    $widgetKey = is_array($widget) ? ($widget['widget_key'] ?? null) : $widget;

                    if (is_string($widgetKey) && $widgetKey !== '') {
                        $widgets[] = $widgetKey;
                    }
                }
            }

            return array_values(array_unique($widgets));
        });
    }
}
