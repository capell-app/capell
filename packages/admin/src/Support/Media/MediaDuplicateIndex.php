<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Media;

use Capell\Admin\Support\MediaScope;
use Capell\Core\Models\Media;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class MediaDuplicateIndex
{
    /** @var array<int, true>|null */
    private ?array $duplicateIds = null;

    public function contains(Media $media): bool
    {
        return isset($this->duplicateIds()[(int) $media->getKey()]);
    }

    /** @return array<int, true> */
    private function duplicateIds(): array
    {
        if ($this->duplicateIds !== null) {
            return $this->duplicateIds;
        }

        /** @var array<int, list<Media>> $mediaBySize */
        $mediaBySize = [];

        MediaScope::applyForCurrentActor(
            Media::query()->whereNull('deleted_at')->where('size', '>', 0),
        )
            ->select(['id', 'disk', 'size', 'file_name', 'model_type', 'model_id', 'uuid'])
            ->orderBy('id')
            ->chunkById(200, function (Collection $mediaChunk) use (&$mediaBySize): void {
                foreach ($mediaChunk as $media) {
                    if (! $media instanceof Media) {
                        continue;
                    }

                    $size = (int) $media->getAttribute('size');
                    $mediaBySize[$size][] = $media;
                }
            });

        $duplicateIds = [];

        foreach ($mediaBySize as $candidates) {
            if (count($candidates) < 2) {
                continue;
            }

            /** @var array<string, list<int>> $idsByHash */
            $idsByHash = [];

            foreach ($candidates as $media) {
                $hash = $this->contentHash($media);

                if ($hash === null) {
                    continue;
                }

                $idsByHash[$hash][] = (int) $media->getKey();
            }

            foreach ($idsByHash as $ids) {
                if (count($ids) < 2) {
                    continue;
                }

                foreach ($ids as $id) {
                    $duplicateIds[$id] = true;
                }
            }
        }

        return $this->duplicateIds = $duplicateIds;
    }

    private function contentHash(Media $media): ?string
    {
        $disk = $media->getAttribute('disk');

        if (! is_string($disk) || $disk === '') {
            return null;
        }

        try {
            $storage = Storage::disk($disk);
            $path = $media->getPathRelativeToRoot();

            if ($path === '' || ! $storage->exists($path)) {
                return null;
            }

            return $this->hashStorageFile($storage, $path);
        } catch (Throwable) {
            return null;
        }
    }

    private function hashStorageFile(Filesystem $storage, string $path): ?string
    {
        $stream = $storage->readStream($path);

        if (! is_resource($stream)) {
            return null;
        }

        $hashContext = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);

                if ($chunk === false) {
                    return null;
                }

                hash_update($hashContext, $chunk);
            }

            return hash_final($hashContext);
        } finally {
            fclose($stream);
        }
    }
}
