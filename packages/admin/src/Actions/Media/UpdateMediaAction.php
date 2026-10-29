<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Admin\Exceptions\MediaDerivativesFailedException;
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
use Throwable;

final class UpdateMediaAction
{
    use AsFake;
    use AsObject;

    /**
     * Image edits are atomic: if derived files cannot be generated the metadata
     * rolls back, the previous derivatives are rebuilt on a best-effort basis,
     * and the caller can retry the same submission.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws MediaDerivativesFailedException
     */
    public function handle(Media $media, array $data): Media
    {
        $previousCropPresetNames = $media->getCropPresetNames();
        $usesSpatieImageEditing = $media->isImage() && resolve(BackendResolver::class)->isSpatie();

        try {
            return $media->getConnection()->transaction(function () use ($media, $data, $usesSpatieImageEditing): Media {
                $this->applyChanges($media, $data, $usesSpatieImageEditing);

                return $media;
            });
        } catch (ValidationException $exception) {
            $media->refresh();

            throw $exception;
        } catch (Throwable $throwable) {
            $media->refresh();

            throw_unless($usesSpatieImageEditing, $throwable);

            $this->restoreDerivedFiles($media, $previousCropPresetNames);

            report($throwable);

            throw MediaDerivativesFailedException::afterRollback($throwable);
        }
    }

    /** @param array<string, mixed> $data */
    private function applyChanges(Media $media, array $data, bool $usesSpatieImageEditing): void
    {
        $media->name = (string) $data['name'];
        $externalVideoUrl = trim((string) ($data['external_video_url'] ?? ''));

        $this->applyExternalVideo($media, $externalVideoUrl);

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
    }

    private function applyExternalVideo(Media $media, string $externalVideoUrl): void
    {
        if ($media->isFileBacked()) {
            // An uploaded file never becomes a video through a metadata edit.
            if ($externalVideoUrl !== '') {
                throw ValidationException::withMessages([
                    'external_video_url' => __('capell-admin::media.external_video_file_backed'),
                ]);
            }

            return;
        }

        if ($externalVideoUrl === '') {
            throw ValidationException::withMessages([
                'external_video_url' => __('capell-admin::media.external_video_required'),
            ]);
        }

        $externalVideo = YouTubeVideoUrl::parse($externalVideoUrl);

        if (! $externalVideo instanceof ExternalVideoData) {
            throw ValidationException::withMessages([
                'external_video_url' => __('capell-admin::media.external_video_invalid'),
            ]);
        }

        $media->setExternalVideo($externalVideo);
    }

    /** @param list<string> $cropPresetNames */
    private function restoreDerivedFiles(Media $media, array $cropPresetNames): void
    {
        try {
            resolve(FileManipulator::class)->createDerivedFiles(
                $media,
                $cropPresetNames,
                withResponsiveImages: true,
            );
        } catch (Throwable $throwable) {
            // The editor is already told to retry; the retry regenerates every selected preset.
            report($throwable);
        }
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

            if (! $media->isImage()) {
                $existingTranslation = $media->translations()->where('language_id', $languageId)->first();
                $existingMeta = $existingTranslation instanceof Translation ? ($existingTranslation->meta ?? []) : [];

                // Image-only fields are absent from document forms; retain historic values.
                foreach (['alt', 'decorative'] as $key) {
                    unset($meta[$key]);

                    if (array_key_exists($key, $existingMeta)) {
                        $meta[$key] = $existingMeta[$key];
                    }
                }
            }

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
