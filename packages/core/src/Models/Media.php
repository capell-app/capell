<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Data\Media\ExternalVideoData;
use Capell\Core\Database\Factories\MediaFactory;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Concerns\HasAssets;
use Capell\Core\Models\Concerns\HasTranslations;
use Capell\Core\Models\Contracts\Translatable;
use Capell\Core\Support\Media\LocalizedMediaMetadata;
use Capell\Core\Support\Media\LocalizedMediaMetadataResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;

/**
 * @property int $id
 * @property string $model_type
 * @property int $model_id
 * @property string|null $uuid
 * @property string $collection_name
 * @property string $name
 * @property string $file_name
 * @property string|null $mime_type
 * @property string $disk
 * @property string|null $conversions_disk
 * @property int $size
 * @property array<array-key, mixed> $manipulations
 * @property array<array-key, mixed> $custom_properties
 * @property array<array-key, mixed> $generated_conversions
 * @property array<array-key, mixed> $responsive_images
 * @property int|null $order_column
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read mixed $extension
 * @property-read mixed $human_readable_size
 * @property-read Model $model
 * @property-read mixed $original_url
 * @property-read mixed $preview_url
 * @property-read mixed $type
 *
 * @method static MediaCollection<int, static> all($columns = ['*'])
 * @method static MediaFactory factory($count = null, $state = [])
 * @method static MediaCollection<int, static> get($columns = ['*'])
 * @method static Builder<static>|Media newModelQuery()
 * @method static Builder<static>|Media newQuery()
 * @method static Builder<static>|Media ordered()
 * @method static Builder<static>|Media query()
 * @method static Builder<static>|Media whereCollectionName($value)
 * @method static Builder<static>|Media whereConversionsDisk($value)
 * @method static Builder<static>|Media whereCreatedAt($value)
 * @method static Builder<static>|Media whereCustomProperties($value)
 * @method static Builder<static>|Media whereDisk($value)
 * @method static Builder<static>|Media whereFileName($value)
 * @method static Builder<static>|Media whereGeneratedConversions($value)
 * @method static Builder<static>|Media whereId($value)
 * @method static Builder<static>|Media whereManipulations($value)
 * @method static Builder<static>|Media whereMimeType($value)
 * @method static Builder<static>|Media whereModelId($value)
 * @method static Builder<static>|Media whereModelType($value)
 * @method static Builder<static>|Media whereName($value)
 * @method static Builder<static>|Media whereOrderColumn($value)
 * @method static Builder<static>|Media whereResponsiveImages($value)
 * @method static Builder<static>|Media whereSize($value)
 * @method static Builder<static>|Media whereUpdatedAt($value)
 * @method static Builder<static>|Media whereUuid($value)
 *
 * @mixin Model
 */
class Media extends \Spatie\MediaLibrary\MediaCollections\Models\Media implements MediaContract, Translatable
{
    use HasAssets;

    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    use HasTranslations;

    // Soft-delete window: physical files stay on disk until
    // PurgeSoftDeletedMediaCommand reclaims them after a grace period.
    // Gives editors recovery time for accidental deletes.
    use SoftDeletes;

    protected static string $factory = MediaFactory::class;

    /**
     * @param  array<string, mixed>|null  $attr
     */
    public function onCloning(self $src, ?bool $child = null, ?array $attr = null): void
    {
        $this->uuid = (string) Str::uuid();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMimeType(): string
    {
        return (string) $this->mime_type;
    }

    public function getWidth(): ?int
    {
        $value = $this->getCustomProperty('width');

        return $value === null ? null : (int) $value;
    }

    public function getHeight(): ?int
    {
        $value = $this->getCustomProperty('height');

        return $value === null ? null : (int) $value;
    }

    public function hasConversion(string $conversion): bool
    {
        return $this->hasGeneratedConversion($conversion);
    }

    public function localizedMetadata(int|string|Language|null $language = null): LocalizedMediaMetadata
    {
        return resolve(LocalizedMediaMetadataResolver::class)->for($this, $language);
    }

    public function getAltText(int|string|Language|null $language = null): ?string
    {
        $metadata = $this->localizedMetadata($language);

        return $metadata->decorative ? '' : $metadata->alt;
    }

    public function getCaption(int|string|Language|null $language = null): ?string
    {
        return $this->localizedMetadata($language)->caption;
    }

    public function getCredit(int|string|Language|null $language = null): ?string
    {
        return $this->localizedMetadata($language)->credit;
    }

    public function isDecorative(int|string|Language|null $language = null): bool
    {
        return $this->localizedMetadata($language)->decorative;
    }

    public function isImage(): bool
    {
        return is_string($this->mime_type) && str_starts_with($this->mime_type, 'image/');
    }

    public function isExternalVideo(): bool
    {
        return $this->externalVideo() instanceof ExternalVideoData;
    }

    public function externalVideo(): ?ExternalVideoData
    {
        $data = data_get($this->custom_properties, 'capell.video');

        return is_array($data) ? ExternalVideoData::fromArray($data) : null;
    }

    public function setExternalVideo(ExternalVideoData $video): self
    {
        $properties = $this->custom_properties;
        data_set($properties, 'capell.video', $video->toArray());
        $this->custom_properties = $properties;
        $this->collection_name = MediaCollectionEnum::Video->value;
        $this->mime_type = 'video/youtube';
        $this->file_name = $video->videoId . '.youtube';
        $this->size = 0;

        return $this;
    }

    public function clearExternalVideo(): self
    {
        $properties = $this->custom_properties;
        data_forget($properties, 'capell.video');
        $this->custom_properties = $properties;

        return $this;
    }

    /**
     * @return array{x: int, y: int}
     */
    public function getFocalPoint(): array
    {
        return [
            'x' => $this->normalizePercentage(data_get($this->custom_properties, 'capell.focal.x', 50)),
            'y' => $this->normalizePercentage(data_get($this->custom_properties, 'capell.focal.y', 50)),
        ];
    }

    public function setFocalPoint(int $x, int $y): self
    {
        $properties = $this->custom_properties;

        data_set($properties, 'capell.focal', [
            'x' => $this->normalizePercentage($x),
            'y' => $this->normalizePercentage($y),
        ]);

        $this->custom_properties = $properties;

        return $this;
    }

    /**
     * @return array<string, array{focal: array{x: int, y: int}, updated_at: string|null}>
     */
    public function getCropPresets(): array
    {
        $presets = data_get($this->custom_properties, 'capell.crops', []);

        if (! is_array($presets)) {
            return [];
        }

        return collect($presets)
            ->mapWithKeys(function (mixed $preset, string|int $name): array {
                if (! is_string($name) || ! is_array($preset)) {
                    return [];
                }

                return [
                    $name => [
                        'focal' => [
                            'x' => $this->normalizePercentage(data_get($preset, 'focal.x', data_get($this->getFocalPoint(), 'x'))),
                            'y' => $this->normalizePercentage(data_get($preset, 'focal.y', data_get($this->getFocalPoint(), 'y'))),
                        ],
                        'updated_at' => is_string($preset['updated_at'] ?? null) ? $preset['updated_at'] : null,
                    ],
                ];
            })
            ->all();
    }

    /**
     * @param  list<string>  $presetNames
     */
    public function setCropPresets(array $presetNames): self
    {
        $properties = $this->custom_properties;
        $focal = $this->getFocalPoint();
        $timestamp = now()->toISOString();
        $crops = [];

        foreach (array_values(array_unique($presetNames)) as $presetName) {
            if ($presetName === '') {
                continue;
            }

            $crops[$presetName] = [
                'focal' => $focal,
                'updated_at' => $timestamp,
            ];
        }

        data_set($properties, 'capell.crops', $crops);

        $this->custom_properties = $properties;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getCropPresetNames(): array
    {
        return array_keys($this->getCropPresets());
    }

    /**
     * @return array{x: int, y: int}
     */
    public function getFocalPointForConversion(string $conversion): array
    {
        $crop = $this->getCropPresets()[$conversion]['focal'] ?? null;

        if (is_array($crop)) {
            return [
                'x' => $this->normalizePercentage($crop['x']),
                'y' => $this->normalizePercentage($crop['y']),
            ];
        }

        return $this->getFocalPoint();
    }

    /**
     * How many AssetAttachment rows reference this media item.
     *
     * Surfaces in the MediaResource list so editors can see at a glance
     * whether deleting an image will break N pages — the most common
     * "I deleted it by accident, now everything's missing" footgun.
     */
    protected function getUsageCountAttribute(): int
    {
        return AssetAttachment::query()
            ->where('asset_type', $this->getMorphClass())
            ->where('asset_id', (string) $this->getKey())
            ->count();
    }

    private function normalizePercentage(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 50;
        }

        return max(0, min(100, (int) round((float) $value)));
    }
}
