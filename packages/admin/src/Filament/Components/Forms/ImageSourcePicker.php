<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Support\Enums\EnumPresentationRegistry;
use Capell\Core\Enums\ImageSourceType;
use Capell\Core\Settings\CoreSettings;
use Capell\Core\Support\Media\ImageSourcePolicyResolver;
use Capell\Core\Support\Media\ImageSourcePresets;
use Capell\Core\Support\Media\ImageUrlPolicy;
use Capell\Core\Support\Media\StorableUploadRule;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;
use Override;
use Throwable;

class ImageSourcePicker extends Group
{
    protected string $mediaName = 'image';

    protected string $sourceStatePath = 'image_source';

    /** @var list<ImageSourceType> */
    protected array $allowedSources = [];

    protected string $defaultSource = 'auto';

    #[Override]
    public static function make(array|Closure|string $schema = []): static
    {
        $component = resolve(static::class, ['schema' => []]);

        if (is_string($schema)) {
            $component->mediaName = $schema;
            $component->sourceStatePath = $schema . '_source';
        } else {
            $component->schema($schema);
        }

        $component->configure();
        $component->allowedSources(resolve(ImageSourcePolicyResolver::class)->allowedSources());
        $component->rebuildImageSourceSchema();

        return $component;
    }

    public function sourceStatePath(string $path): static
    {
        $this->sourceStatePath = $path;
        $this->rebuildImageSourceSchema();

        return $this;
    }

    /**
     * @param  list<ImageSourceType|string>|string|ImageSourceType|null  $sources
     */
    public function allowedSources(string|array|ImageSourceType|null $sources): static
    {
        $this->allowedSources = ImageSourcePresets::resolve($sources);
        $this->rebuildImageSourceSchema();

        return $this;
    }

    /**
     * @param  list<ImageSourceType|string>|string|ImageSourceType|null  $schemaSources
     * @param  list<ImageSourceType|string>|string|ImageSourceType|null  $blueprintSources
     */
    public function imageSourcePolicy(
        string|array|ImageSourceType|null $schemaSources = null,
        string|array|ImageSourceType|null $blueprintSources = null,
    ): static {
        $this->allowedSources = resolve(ImageSourcePolicyResolver::class)->allowedSources(
            schemaSources: $schemaSources,
            blueprintSources: $blueprintSources,
        );
        $this->rebuildImageSourceSchema();

        return $this;
    }

    public function defaultSource(string|ImageSourceType|null $source): static
    {
        $this->defaultSource = $source instanceof ImageSourceType ? $source->value : ($source ?? 'auto');
        $this->rebuildImageSourceSchema();

        return $this;
    }

    public function hiddenLabel(bool $condition = true): static
    {
        return $this;
    }

    protected static function selectedSourceStoresMedia(string $source): bool
    {
        return ImageSourceType::tryFrom($source)?->storesMediaRelation() ?? false;
    }

    protected function rebuildImageSourceSchema(): void
    {
        if ($this->allowedSources === []) {
            return;
        }

        $typePath = $this->sourceStatePath . '.type';
        $urlPath = $this->sourceStatePath . '.url';
        $uploadPath = $this->sourceStatePath . '.path';
        $allowedValues = array_map(static fn (ImageSourceType $source): string => $source->value, $this->allowedSources);
        $defaultSource = $this->resolveDefaultSource($allowedValues);

        $mediaField = MediaLibraryFileUpload::make($this->mediaName)
            ->visibleJs($this->sourceVisibilityJs($typePath, ImageSourceType::Media, $defaultSource))
            ->saved(static fn (Get $get): bool => self::selectedSourceStoresMedia((string) ($get($typePath) ?? $defaultSource)))
            ->columnSpanFull();

        // ID-based adapters persist a reference beside the source descriptor
        // (e.g. meta.image_id). Relationship-backed uploaders keep their state.
        if ($mediaField->getName() === $this->mediaName . '_id') {
            $prefix = str_contains($this->sourceStatePath, '.')
                ? Str::beforeLast($this->sourceStatePath, '.') . '.'
                : '';

            $mediaField->statePath($prefix . $mediaField->getName());
        }

        $this
            ->columns(1)
            ->columnSpanFull()
            ->schema([
                $this->sourceSelector($typePath, $defaultSource),
                TextInput::make($urlPath)
                    ->label(__('capell-admin::form.image_source_url'))
                    ->helperText(__('capell-admin::form.image_source_url_helper'))
                    ->placeholder('https://images.unsplash.com/photo-1497366754035-f200968a6e72')
                    ->visibleJs($this->sourceVisibilityJs($typePath, ImageSourceType::Url, $defaultSource))
                    ->dehydrated(static fn (Get $get): bool => ($get($typePath) ?? $defaultSource) === ImageSourceType::Url->value)
                    ->rules([
                        static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                            if (blank($value)) {
                                return;
                            }

                            if (! resolve(ImageUrlPolicy::class)->allows((string) $value)) {
                                $fail(__('capell-admin::form.image_source_url_invalid'));
                            }
                        },
                    ])
                    ->columnSpanFull(),
                FileUpload::make($uploadPath)
                    ->label(__('capell-admin::form.image_source_upload'))
                    ->helperText(__('capell-admin::form.image_source_upload_helper'))
                    ->image()
                    // This file is served from the site origin, so an SVG must be a static drawing.
                    ->rule(new StorableUploadRule)
                    ->disk('public')
                    ->directory('capell/image-sources')
                    ->visibility('public')
                    ->visibleJs($this->sourceVisibilityJs($typePath, ImageSourceType::Upload, $defaultSource))
                    ->dehydrated(static fn (Get $get): bool => ($get($typePath) ?? $defaultSource) === ImageSourceType::Upload->value)
                    ->columnSpanFull(),
                $mediaField,
            ]);
    }

    protected function sourceSelector(string $typePath, string $defaultSource): Field
    {
        if (count($this->allowedSources) === 1) {
            return Hidden::make($typePath)
                ->default($defaultSource);
        }

        return ToggleButtons::make($typePath)
            ->label(__('capell-admin::form.image_source_type'))
            ->options($this->sourceOptions())
            ->default($defaultSource)
            ->formatStateUsing(static fn (?string $state): string => $state ?? $defaultSource)
            ->grouped()
            ->columnSpanFull();
    }

    /**
     * Records saved before a source was chosen have no type yet; treat them as
     * the default source so an input is always visible instead of a bare choice.
     */
    protected function sourceVisibilityJs(string $typePath, ImageSourceType $source, string $defaultSource): string
    {
        return sprintf("(\$get('%s') ?? '%s') === '%s'", $typePath, $defaultSource, $source->value);
    }

    /**
     * @return array<string, string>
     */
    protected function sourceOptions(): array
    {
        // Library first: it is the usual choice, and the others are fallbacks.
        $order = [ImageSourceType::Media, ImageSourceType::Upload, ImageSourceType::Url];

        return collect($this->allowedSources)
            ->sortBy(static fn (ImageSourceType $source): int => in_array($source, $order, true) ? (int) array_search($source, $order, true) : count($order))
            ->mapWithKeys(static fn (ImageSourceType $source): array => [$source->value => match ($source) {
                ImageSourceType::Media => (string) __('capell-admin::form.image_source_option_media'),
                ImageSourceType::Upload => (string) __('capell-admin::form.image_source_option_upload'),
                ImageSourceType::Url => (string) __('capell-admin::form.image_source_option_url'),
                default => resolve(EnumPresentationRegistry::class)->label($source),
            }])
            ->all();
    }

    /**
     * @param  list<string>  $allowedValues
     */
    protected function resolveDefaultSource(array $allowedValues): string
    {
        if (count($allowedValues) === 1) {
            return $allowedValues[0];
        }

        $candidate = $this->defaultSource;

        if ($candidate === 'auto') {
            try {
                $candidate = resolve(CoreSettings::class)->default_image_source;
            } catch (Throwable) {
                $candidate = ImageSourceType::Media->value;
            }
        }

        return in_array($candidate, $allowedValues, true) ? $candidate : $allowedValues[0];
    }
}
