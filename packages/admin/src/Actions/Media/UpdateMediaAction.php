<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Core\Data\Media\ExternalVideoData;
use Capell\Core\Models\Media;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Media\BackendResolver;
use Capell\Core\Support\Media\MediaCropPresetRepository;
use Capell\Core\Support\Media\YouTubeVideoUrl;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\MediaLibrary\Conversions\FileManipulator;

final class UpdateMediaAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed> $data */
    public function handle(Media $media, array $data): Media
    {
        $media->name = (string) $data['name'];
        $externalVideoUrl = trim((string) ($data['external_video_url'] ?? ''));

        if ($externalVideoUrl !== '') {
            $externalVideo = YouTubeVideoUrl::parse($externalVideoUrl);

            if (! $externalVideo instanceof ExternalVideoData) {
                throw ValidationException::withMessages([
                    'external_video_url' => __('capell-admin::media.external_video_invalid'),
                ]);
            }

            $media->setExternalVideo($externalVideo);
        } elseif ($media->isExternalVideo()) {
            $media->clearExternalVideo();
        }

        $usesSpatieImageEditing = $media->isImage() && resolve(BackendResolver::class)->isSpatie();

        if ($usesSpatieImageEditing) {
            $media
                ->setFocalPoint((int) $data['focal_point_x'], (int) $data['focal_point_y'])
                ->setCropPresets($this->validCropPresetNames($data['crop_presets'] ?? []));
        }

        $media->save();

        $this->syncLocalizedMetadata($media, $data['translations'] ?? []);

        if ($usesSpatieImageEditing) {
            resolve(FileManipulator::class)->createDerivedFiles(
                $media->refresh(),
                $media->getCropPresetNames(),
                withResponsiveImages: true,
            );
        }

        return $media;
    }

    /** @return list<string> */
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

    private function syncLocalizedMetadata(Media $media, mixed $translations): void
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
            if ($languageId < 1 || in_array($languageId, $seenLanguageIds, true)) {
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
                    'translatable_type' => $media->getMorphClass(),
                    'translatable_id' => $media->getKey(),
                ],
                [
                    'title' => $translationData['title'] ?? null,
                    'meta' => $meta,
                ],
            );
        }

        $media->translations()
            ->whereNotIn('language_id', $seenLanguageIds)
            ->delete();
    }
}
