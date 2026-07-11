<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Media\Pages;

use Capell\Admin\Contracts\Extenders\MediaEditActionExtender;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Concerns\HasConfigurableFormActionPosition;
use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Admin\Filament\Resources\Media\Tables\MediaTable;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Data\Media\ExternalVideoData;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Media\BackendResolver;
use Capell\Core\Support\Media\MediaCropPresetRepository;
use Capell\Core\Support\Media\YouTubeVideoUrl;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Override;
use Spatie\MediaLibrary\Conversions\FileManipulator;

/**
 * @property Media $record
 */
class EditMedia extends EditRecord
{
    use HasConfigurableFormActionPosition;

    /** @return class-string<MediaResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<MediaResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Media);

        return $resource;
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        $tabs = [];

        if (resolve(BackendResolver::class)->isSpatie()) {
            $tabs[] = Tab::make(__('capell-admin::media.tabs.crop'))
                ->icon('heroicon-o-arrows-pointing-out')
                ->schema($this->cropSchema());
        }

        $tabs[] = Tab::make(__('capell-admin::media.tabs.metadata'))
            ->icon('heroicon-o-language')
            ->schema($this->metadataSchema());

        return $schema
            ->columns(1)
            ->components([
                Tabs::make('media')
                    ->tabs($tabs)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function cropSchema(): array
    {
        /** @var view-string $cropPreviewView */
        $cropPreviewView = 'capell-admin::filament.resources.media.crop-preview';

        return [
            Section::make(__('capell-admin::media.crop_heading'))
                ->description(__('capell-admin::media.crop_description'))
                ->schema([
                    View::make($cropPreviewView)
                        ->columnSpanFull(),
                    Grid::make(2)
                        ->schema([
                            Slider::make('focal_point_x')
                                ->label(__('capell-admin::media.focal_point_x'))
                                ->range(0, 100)
                                ->step(1)
                                ->tooltips()
                                ->live(debounce: 150)
                                ->disabled(fn (Media $record): bool => ! $record->isImage()),
                            Slider::make('focal_point_y')
                                ->label(__('capell-admin::media.focal_point_y'))
                                ->range(0, 100)
                                ->step(1)
                                ->tooltips()
                                ->live(debounce: 150)
                                ->disabled(fn (Media $record): bool => ! $record->isImage()),
                        ]),
                    CheckboxList::make('crop_presets')
                        ->label(__('capell-admin::media.crop_presets'))
                        ->helperText(__('capell-admin::media.crop_presets_helper'))
                        ->options(fn (MediaCropPresetRepository $cropPresets): array => $cropPresets->options())
                        ->columns(['default' => 1, 'md' => 2])
                        ->bulkToggleable()
                        ->disabled(fn (Media $record): bool => ! $record->isImage()),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function metadataSchema(): array
    {
        /** @var view-string $usageView */
        $usageView = 'capell-admin::filament.resources.media.usage';

        return [
            Section::make(__('capell-admin::media.file_heading'))
                ->description(__('capell-admin::media.file_description'))
                ->schema([
                    Grid::make(2)
                        ->schema([
                            TextInput::make('name')
                                ->label(__('capell-admin::form.name'))
                                ->helperText(__('capell-admin::media.name_helper'))
                                ->required()
                                ->maxLength(255),
                            TextInput::make('file_name')
                                ->label(__('capell-admin::table.name'))
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('mime_type')
                                ->label(__('capell-admin::table.file_type'))
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('size')
                                ->label(__('capell-admin::table.size'))
                                ->disabled()
                                ->dehydrated(false),
                        ]),
                ]),
            Section::make(__('capell-admin::media.external_video_heading'))
                ->description(__('capell-admin::media.external_video_description'))
                ->schema([
                    TextInput::make('external_video_url')
                        ->label(__('capell-admin::media.external_video_url'))
                        ->helperText(__('capell-admin::media.external_video_url_helper'))
                        ->placeholder(__('capell-admin::media.external_video_url_placeholder'))
                        ->url()
                        ->maxLength(2048),
                ]),
            Section::make(__('capell-admin::media.localized_metadata_heading'))
                ->description(__('capell-admin::media.localized_metadata_description'))
                ->schema([
                    Repeater::make('translations')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('language_id')
                                ->label(__('capell-admin::form.language'))
                                ->options(fn (): array => Language::query()->ordered()->pluck('name', 'id')->all())
                                ->required()
                                ->searchable()
                                ->preload()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                            TextInput::make('title')
                                ->label(__('capell-admin::media.media_title'))
                                ->maxLength(255),
                            TextInput::make('meta.alt')
                                ->label(__('capell-admin::media.alt_text'))
                                ->helperText(__('capell-admin::media.alt_text_helper'))
                                ->required(fn (Get $get): bool => ! (bool) $get('meta.decorative'))
                                ->maxLength(255),
                            Textarea::make('meta.caption')
                                ->label(__('capell-admin::media.caption'))
                                ->helperText(__('capell-admin::media.caption_helper'))
                                ->rows(2)
                                ->columnSpanFull(),
                            TextInput::make('meta.credit')
                                ->label(__('capell-admin::media.credit'))
                                ->maxLength(255),
                            Toggle::make('meta.decorative')
                                ->label(__('capell-admin::media.decorative'))
                                ->live()
                                ->helperText(__('capell-admin::media.decorative_helper')),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel(__('capell-admin::media.add_locale_metadata'))
                        ->columnSpanFull(),
                ]),
            Section::make(__('capell-admin::media.usage_heading'))
                ->description(__('capell-admin::media.usage_description'))
                ->schema([
                    View::make($usageView)
                        ->columnSpanFull(),
                ]),
        ];
    }

    #[Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $focalPoint = $this->record->getFocalPoint();
        $cropPresetNames = $this->record->getCropPresetNames();

        $data['focal_point_x'] = $focalPoint['x'];
        $data['focal_point_y'] = $focalPoint['y'];
        $data['crop_presets'] = $cropPresetNames !== []
            ? $cropPresetNames
            : resolve(MediaCropPresetRepository::class)->names();
        $data['translations'] = $this->record
            ->translations()
            ->orderBy('language_id')
            ->get()
            ->map(fn (Translation $translation): array => [
                'language_id' => $translation->language_id,
                'title' => $translation->title,
                'meta' => [
                    'alt' => $translation->meta['alt'] ?? null,
                    'caption' => $translation->meta['caption'] ?? null,
                    'credit' => $translation->meta['credit'] ?? null,
                    'decorative' => (bool) ($translation->meta['decorative'] ?? false),
                ],
            ])
            ->values()
            ->all();
        $data['external_video_url'] = $this->record->externalVideo()?->url;

        return $data;
    }

    #[Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Media $record */
        $record->name = (string) $data['name'];
        $externalVideoUrl = trim((string) ($data['external_video_url'] ?? ''));

        if ($externalVideoUrl !== '') {
            $externalVideo = YouTubeVideoUrl::parse($externalVideoUrl);

            if (! $externalVideo instanceof ExternalVideoData) {
                throw ValidationException::withMessages([
                    'external_video_url' => __('capell-admin::media.external_video_invalid'),
                ]);
            }

            $record->setExternalVideo($externalVideo);
        } elseif ($record->isExternalVideo()) {
            $record->clearExternalVideo();
        }

        $usesSpatieImageEditing = $record->isImage() && resolve(BackendResolver::class)->isSpatie();

        if ($usesSpatieImageEditing) {
            $record
                ->setFocalPoint((int) $data['focal_point_x'], (int) $data['focal_point_y'])
                ->setCropPresets($this->validCropPresetNames($data['crop_presets'] ?? []));
        }

        $record->save();

        $this->syncLocalizedMetadata($record, $data['translations'] ?? []);

        if ($usesSpatieImageEditing) {
            resolve(FileManipulator::class)->createDerivedFiles(
                $record->refresh(),
                $record->getCropPresetNames(),
                withResponsiveImages: true,
            );
        }

        return $record;
    }

    #[Override]
    protected function getActions(): array
    {
        return $this->getBaseHeaderActions();
    }

    /** @return array<int, mixed> */
    protected function getBaseHeaderActions(): array
    {
        return [
            Action::make('open-owner')
                ->label(__('capell-admin::media.open_owner'))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): ?string => $this->ownerUrl())
                ->visible(fn (): bool => $this->ownerUrl() !== null),
            ...collect(app()->tagged(MediaEditActionExtender::TAG))
                ->flatMap(fn (MediaEditActionExtender $extender): array => $extender->getHeaderActions($this))
                ->all(),
        ];
    }

    protected function getPositionedFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    /** @return array<int, mixed> */
    protected function getPositionedHeaderFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->submit(null)
                ->action(fn (): mixed => $this->save()),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * @return list<string>
     */
    private function validCropPresetNames(mixed $presetNames): array
    {
        if (! is_array($presetNames)) {
            return [];
        }

        $available = resolve(MediaCropPresetRepository::class)->names();

        $validPresetNames = collect($presetNames)
            ->filter(fn (mixed $name): bool => is_string($name) && in_array($name, $available, true))
            ->values()
            ->all();

        return array_values($validPresetNames);
    }

    private function syncLocalizedMetadata(Media $record, mixed $translations): void
    {
        if (! is_array($translations)) {
            return;
        }

        $seenLanguageIds = [];

        foreach ($translations as $translationData) {
            if (! is_array($translationData)) {
                continue;
            }

            $languageId = (int) ($translationData['language_id'] ?? 0);
            if ($languageId < 1) {
                continue;
            }

            if (in_array($languageId, $seenLanguageIds, true)) {
                continue;
            }

            $seenLanguageIds[] = $languageId;

            $meta = array_filter([
                'alt' => $translationData['meta']['alt'] ?? null,
                'caption' => $translationData['meta']['caption'] ?? null,
                'credit' => $translationData['meta']['credit'] ?? null,
                'decorative' => (bool) ($translationData['meta']['decorative'] ?? false),
            ], fn (mixed $value): bool => $value !== null && $value !== '');

            Translation::query()->updateOrCreate(
                [
                    'language_id' => $languageId,
                    'translatable_type' => $record->getMorphClass(),
                    'translatable_id' => $record->getKey(),
                ],
                [
                    'title' => $translationData['title'] ?? null,
                    'meta' => $meta,
                ],
            );
        }

        $record->translations()
            ->whereNotIn('language_id', $seenLanguageIds)
            ->delete();
    }

    private function ownerUrl(): ?string
    {
        return MediaTable::getOwnerUrl($this->record);
    }
}
