<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Bkwld\Cloner\Cloneable;
use Capell\Core\Concerns\HasCapellMedia;
use Capell\Core\Contracts\Media\HasMediaContract;
use Capell\Core\Database\Factories\ThemeFactory;
use Capell\Core\Models\Concerns\HasBlueprint;
use Capell\Core\Models\Concerns\HasDefault;
use Capell\Core\Models\Concerns\HasMetaData;
use Capell\Core\Models\Concerns\HasStatus;
use Capell\Core\Models\Concerns\HasUserstamps;
use Capell\Core\Models\Contracts\Blueprintable;
use Capell\Core\Models\Contracts\Defaultable;
use Capell\Core\Models\Contracts\Statusable;
use Capell\Core\Models\Contracts\Userstampable;
use Capell\Core\Observers\ThemeObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Override;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;

/**
 * @property int $id
 * @property string $name
 * @property int $blueprint_id
 * @property string $key
 * @property string|null $active_key
 * @property string|null $custom_css
 * @property array<array-key, mixed>|null $meta
 * @property array<array-key, mixed>|null $meta_extra
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
 * @property-read MediaCollection<int, Media> $media
 * @property-read int|null $media_count
 * @property-read AuthenticatableUser|null $creator
 * @property-read bool|null $dark_mode_toggle
 * @property-read AuthenticatableUser|null $destroyer
 * @property-read AuthenticatableUser|null $editor
 * @property-read Collection<int, Layout> $layouts
 * @property-read int|null $layouts_count
 * @property-read list<string> $secondary_containers
 * @property-read array<string, mixed> $colors
 * @property-read Collection<int, Site> $sites
 * @property-read int|null $sites_count
 * @property-read bool $sticky_header
 * @property-read bool $fixed_header
 * @property-read bool $scroll_up_header
 * @property-read Blueprint $blueprint
 * @property-read bool $with_dark_mode_toggle
 *
 * @method static Builder<static>|Theme default(bool $default = true)
 * @method static Builder<static>|Theme disabled()
 * @method static Builder<static>|Theme enabled()
 * @method static ThemeFactory factory($count = null, array<string, mixed> $state = [])
 * @method static Builder<static>|Theme newModelQuery()
 * @method static Builder<static>|Theme newQuery()
 * @method static Builder<static>|Theme nonDefault()
 * @method static Builder<static>|Theme onlyTrashed()
 * @method static Builder<static>|Theme ordered()
 * @method static Builder<static>|Theme query()
 * @method static Builder<static>|Theme status(bool $enabled)
 * @method static Builder<static>|Theme whereAdmin($value)
 * @method static Builder<static>|Theme whereActiveKey($value)
 * @method static Builder<static>|Theme whereCreatedAt($value)
 * @method static Builder<static>|Theme whereCreatedBy($value)
 * @method static Builder<static>|Theme whereCustomCss($value)
 * @method static Builder<static>|Theme whereDefault($value)
 * @method static Builder<static>|Theme whereDeletedAt($value)
 * @method static Builder<static>|Theme whereDeletedBy($value)
 * @method static Builder<static>|Theme whereId($value)
 * @method static Builder<static>|Theme whereKey($value)
 * @method static Builder<static>|Theme whereMeta($value)
 * @method static Builder<static>|Theme whereMetaExtra($value)
 * @method static Builder<static>|Theme whereName($value)
 * @method static Builder<static>|Theme whereOrder($value)
 * @method static Builder<static>|Theme whereStatus($value)
 * @method static Builder<static>|Theme whereBlueprintId($value)
 * @method static Builder<static>|Theme whereUpdatedAt($value)
 * @method static Builder<static>|Theme whereUpdatedBy($value)
 * @method static Builder<static>|Theme withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Theme withoutTrashed()
 * @method null|static duplicate(mixed $attr = null)
 *
 * @mixin Model
 */
#[ObservedBy(ThemeObserver::class)]
class Theme extends Model implements Blueprintable, Defaultable, HasMedia, HasMediaContract, Statusable, Userstampable
{
    use Cloneable;
    use HasBlueprint;
    use HasCapellMedia;

    /** @use HasDefault<Theme> */
    use HasDefault;

    /** @use HasFactory<ThemeFactory> */
    use HasFactory;

    use HasMetaData;

    /** @use HasStatus<Theme> */
    use HasStatus;

    use HasUserstamps;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'blueprint_id',
        'key',
        'active_key',
        'custom_css',
        'meta',
        'meta_extra',
        'admin',
        'order',
        'default',
        'status',
    ];

    protected static string $factory = ThemeFactory::class;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('hero_video_desktop')->singleFile();
        $this->addMediaCollection('hero_video_tablet')->singleFile();
        $this->addMediaCollection('hero_video_mobile')->singleFile();
        $this->addMediaCollection('hero_image_desktop')->singleFile();
        $this->addMediaCollection('hero_image_tablet')->singleFile();
        $this->addMediaCollection('hero_image_mobile')->singleFile();
    }

    public function colorsHaveChanged(): bool
    {
        if (! $this->wasChanged('meta')) {
            return false;
        }

        $originalMeta = $this->getOriginal('meta');

        if (is_array($originalMeta) || is_string($originalMeta) || $originalMeta === null) {
            return $this->extractColorsFromMeta($originalMeta) !== $this->colors;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $colors
     */
    public function colorsDifferFrom(array $colors): bool
    {
        return $this->colors !== $colors;
    }

    public function hasManualAdminImage(): bool
    {
        $admin = is_array($this->admin) ? $this->admin : [];
        $adminImage = $admin['image'] ?? null;

        if (is_string($adminImage) && $adminImage !== '') {
            return true;
        }

        return $this->getFirstMediaUrl('image') !== '';
    }

    public function generatedImageSignature(): string
    {
        $admin = is_array($this->admin) ? $this->admin : [];

        return hash('sha256', json_encode([
            'name' => $this->name,
            'key' => $this->key,
            'colors' => $this->colors,
            'icon' => $admin['icon'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    public function readyGeneratedImage(): ?string
    {
        $admin = is_array($this->admin) ? $this->admin : [];

        if (($admin['generated_image_status'] ?? null) !== 'ready') {
            return null;
        }

        $image = $admin['generated_image'] ?? null;

        return is_string($image) && $image !== '' ? $image : null;
    }

    /** @return HasMany<Layout, $this> */
    public function layouts(): HasMany
    {
        return $this->hasMany(Layout::class);
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * @param  Builder<Theme>  $query
     */
    protected function scopeOrdered(Builder $query): void
    {
        $query->orderBy($this->qualifyColumn('order'))
            ->orderBy($this->qualifyColumn('default'), 'desc')
            ->orderBy($this->qualifyColumn('name'));
    }

    protected function getDarkModeToggleAttribute(): ?bool
    {
        return match ($this->getMeta('dark_mode_toggle', false)) {
            'on' => true,
            'off' => false,
            default => null,
        };
    }

    protected function getStickyHeaderAttribute(): bool
    {
        return $this->getMeta('header_position') === 'sticky';
    }

    protected function getFixedHeaderAttribute(): bool
    {
        return $this->getMeta('header_position') === 'fixed';
    }

    protected function getScrollUpHeaderAttribute(): bool
    {
        return $this->getMeta('header_position') === 'scroll_up';
    }

    /**
     * @return list<string>
     */
    protected function getSecondaryContainersAttribute(): array
    {
        return $this->getMeta('secondary_containers') ?? ['sidebar'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getColorsAttribute(): array
    {
        return (array) ($this->getMeta('colors') ?? []);
    }

    protected function getWithDarkModeAttribute(): bool
    {
        return $this->dark_mode_toggle !== false;
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
            'meta_extra' => 'json',
            'admin' => 'json',
            'default' => 'boolean',
            'status' => 'boolean',
        ];
    }

    /**
     * @param  array<string, mixed>|string|null  $meta
     * @return array<string, mixed>
     */
    private function extractColorsFromMeta(array|string|null $meta): array
    {
        if (is_array($meta)) {
            $rawColors = $meta['colors'] ?? [];

            return is_array($rawColors) ? $rawColors : [];
        }

        if (is_string($meta) && $meta !== '') {
            $decodedMeta = json_decode($meta, true);

            if (is_array($decodedMeta)) {
                $rawColors = $decodedMeta['colors'] ?? [];

                return is_array($rawColors) ? $rawColors : [];
            }
        }

        return [];
    }
}
