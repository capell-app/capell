<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Awobaz\Compoships\Compoships;
use Capell\Core\Actions\Content\ExtractTextContentAction;
use Capell\Core\Casts\DynamicContentCast;
use Capell\Core\Concerns\HasCapellMedia;
use Capell\Core\Contracts\Media\HasMediaContract;
use Capell\Core\Database\Factories\TranslationFactory;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Enums\TranslatableType;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Concerns\HasMetaData;
use Capell\Core\Models\Concerns\HasUserstamps;
use Capell\Core\Models\Contracts\Userstampable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;

/**
 * @property int $id
 * @property int $language_id
 * @property string $translatable_type
 * @property int $translatable_id
 * @property string|null $title
 * @property mixed|null $content
 * @property array<array-key, mixed>|null $meta
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Media|null $image
 * @property-read Media|null $backgroundImage
 * @property-read MediaCollection<int, Media> $media
 * @property-read int|null $media_count
 * @property-read Collection<int, Activity> $activities
 * @property-read int|null $activities_count
 * @property-read AuthenticatableUser|null $creator
 * @property-read AuthenticatableUser|null $destroyer
 * @property-read AuthenticatableUser|null $editor
 * @property-read string|null $summary
 * @property-read mixed $label
 * @property-read Language $language
 * @property-read PageUrl|null $pageUrl
 * @property-read string|null $link_text
 * @property-read Model $translatable
 * @property-read string|null $slug
 * @property-read string|null $meta_description
 * @property-read string|null $meta_keywords
 * @property-read string|null $meta_title
 *
 * @method static TranslationFactory factory($count = null, $state = [])
 * @method static Builder<static>|Translation newModelQuery()
 * @method static Builder<static>|Translation newQuery()
 * @method static Builder<static>|Translation query()
 * @method static Builder<static>|Translation whereContent($value)
 * @method static Builder<static>|Translation whereCreatedAt($value)
 * @method static Builder<static>|Translation whereCreatedBy($value)
 * @method static Builder<static>|Translation whereDeletedBy($value)
 * @method static Builder<static>|Translation whereId($value)
 * @method static Builder<static>|Translation whereLanguageId($value)
 * @method static Builder<static>|Translation whereMeta($value)
 * @method static Builder<static>|Translation whereTitle($value)
 * @method static Builder<static>|Translation whereTranslatableId($value)
 * @method static Builder<static>|Translation whereTranslatableType($value)
 * @method static Builder<static>|Translation whereUpdatedAt($value)
 * @method static Builder<static>|Translation whereUpdatedBy($value)
 *
 * @mixin Model
 */
class Translation extends Model implements HasMedia, HasMediaContract, Userstampable
{
    use Compoships;
    use HasCapellMedia;

    /** @use HasFactory<TranslationFactory> */
    use HasFactory;

    use HasMetaData;
    use HasUserstamps;
    use LogsActivity;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'content',
        'language_id',
        'meta',
        'title',
        'translatable_type',
        'translatable_id',
    ];

    protected static string $factory = TranslationFactory::class;

    public function hasContent(): bool
    {
        return (is_string($this->content) && $this->content !== '') || (is_string($this->title) && $this->title !== '');
    }

    public function isPage(): bool
    {
        return $this->translatable_type === TranslatableType::Page->value;
    }

    public function isPageable(): bool
    {
        return CapellCore::hasPageVariation($this->translatable_type);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('translation')
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

    /** @return BelongsTo<Language, $this> */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /** @return MorphTo<Model, $this> */
    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<PageUrl, $this> */
    public function pageUrl(): BelongsTo
    {
        return $this->belongsTo(
            PageUrl::class,
            ['translatable_type', 'translatable_id', 'language_id'],
            ['pageable_type', 'pageable_id', 'language_id'],
        )
            ->whereIn('pageable_type', CapellCore::getPageVariationNames());
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(MediaCollectionEnum::Image->value)->singleFile();
        $this->addMediaCollection(MediaCollectionEnum::BackgroundImage->value)->singleFile();
    }

    /** @return MorphOne<Media, $this> */
    public function image(): MorphOne
    {
        return $this->morphOne(Media::class, 'model')
            ->where('collection_name', MediaCollectionEnum::Image->value);
    }

    /** @return MorphOne<Media, $this> */
    public function backgroundImage(): MorphOne
    {
        return $this->morphOne(Media::class, 'model')
            ->where('collection_name', MediaCollectionEnum::BackgroundImage->value);
    }

    /** @return Attribute<string|null, never> */
    protected function label(): Attribute
    {
        return Attribute::make(get: function (): ?string {
            $label = $this->meta['label'] ?? null;

            if (! is_string($label) || $label === '') {
                $title = $this->attributes['title'] ?? null;

                return is_string($title) ? $title : null;
            }

            return $label;
        });
    }

    /** @return Attribute<string|null, never> */
    protected function linkText(): Attribute
    {
        return Attribute::make(get: function (): ?string {
            $linkText = $this->meta['link_text'] ?? null;

            if ($linkText === null || $linkText === '') {
                return $this->label;
            }

            return $linkText;
        });
    }

    /** @return Attribute<string|null, never> */
    protected function slug(): Attribute
    {
        return Attribute::make(get: fn (): ?string => $this->meta['slug'] ?? null);
    }

    /** @return Attribute<string|null, never> */
    protected function summary(): Attribute
    {
        return Attribute::make(get: function (): ?string {
            if (($this->meta['summary'] ?? null) !== null && $this->meta['summary'] !== '') {
                return $this->meta['summary'];
            }

            if ($this->content !== null && $this->content !== '') {
                return str($this->content)->stripTags()->words(200)->toString();
            }

            return null;
        });
    }

    /** @return Attribute<string, never> */
    protected function metaDescription(): Attribute
    {
        return Attribute::make(get: function (): string {
            $meta = (array) $this->meta;
            $description = $meta['description'] ?? null;

            if ($description !== null && $description !== '') {
                return $description;
            }

            return ExtractTextContentAction::run($this->content, 120) ?? '';
        });
    }

    /** @return Attribute<string, never> */
    protected function metaKeywords(): Attribute
    {
        return Attribute::make(get: function (): string {
            $meta = (array) $this->meta;
            $keywords = $meta['keywords'] ?? null;

            if ($keywords !== null && $keywords !== '') {
                return $keywords;
            }

            return '';
        });
    }

    /** @return Attribute<string, never> */
    protected function metaTitle(): Attribute
    {
        return Attribute::make(get: function (): string {
            $meta = (array) $this->meta;
            $title = $meta['title'] ?? null;

            if ($title !== null && $title !== '') {
                return $title;
            }

            return '';
        });
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
            'content' => DynamicContentCast::class,
        ];
    }
}
