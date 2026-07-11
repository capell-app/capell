<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Backend-agnostic owner-model API for models that hold media.
 *
 * Models using Capell\Core\Concerns\HasCapellMedia satisfy this contract via
 * Spatie's InteractsWithMedia plus a small shim for addMediaFromUploadedFile.
 * Typing against this interface (instead of Spatie's HasMedia) lets the
 * capell/media-library plugin swap in its own trait without every consumer
 * changing.
 *
 * Method signatures are intentionally loose (few return types, plus PHPDoc
 * annotations) so that Spatie's InteractsWithMedia trait methods satisfy the
 * contract without PHP covariance violations. Consumers should rely on the
 * documented PHPDoc return blueprints.
 */
interface HasMediaContract
{
    /**
     * @return Collection<int, MediaContract>
     */
    public function getMedia(string $collection = 'default');

    /**
     * @return MediaContract|null
     */
    public function getFirstMedia(string $collection = 'default');

    public function getFirstMediaUrl(string $collection = 'default', string $conversion = ''): string;

    public function addMediaFromUploadedFile(UploadedFile $file, string $collection = 'default'): MediaContract;

    /**
     * @return static
     */
    public function clearMediaCollection(string $collection = 'default');
}
