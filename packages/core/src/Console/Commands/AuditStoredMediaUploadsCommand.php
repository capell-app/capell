<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Exceptions\UploadContentRefusedException;
use Capell\Core\Models\Media;
use Capell\Core\Support\Media\UploadContentPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Finds media stored before the upload content policy existed that the policy
 * would now refuse: a type a browser runs as a page, or an SVG that is not a
 * static drawing. Such a file is still served from the site origin.
 *
 * Without options it only reports, and fails when it finds any, so it can gate
 * a launch or a monitor. --quarantine moves each original to the private disk
 * and soft-deletes its media record; nothing is destroyed.
 */
final class AuditStoredMediaUploadsCommand extends Command
{
    public const string QUARANTINE_DISK = 'local';

    public const string QUARANTINE_DIRECTORY = 'capell-media-quarantine';

    protected $signature = 'capell:audit-media-uploads
        {--quarantine : Move each refused file to private storage and soft-delete its media record}';

    protected $description = 'Report stored media files that the upload content policy now refuses.';

    public function handle(UploadContentPolicy $policy): int
    {
        $quarantine = (bool) $this->option('quarantine');
        $outcome = $this->scan($policy, $quarantine);

        if ($outcome['refused'] === 0) {
            $this->info('Every stored media file is one the upload content policy accepts.');

            return self::SUCCESS;
        }

        if (! $quarantine) {
            $this->error(sprintf('%d stored media file(s) would be refused today and are still served. Review them, then run this command with --quarantine to move them to private storage.', $outcome['refused']));

            return self::FAILURE;
        }

        if ($outcome['unresolved'] > 0) {
            $this->error(sprintf('%d of %d refused file(s) could not be quarantined and are still served. Check the storage errors above and run the command again.', $outcome['unresolved'], $outcome['refused']));

            return self::FAILURE;
        }

        $this->info(sprintf('Moved %d file(s) to %s on the %s disk and soft-deleted their media records. Restore a record after replacing its file if a page still needs it.', $outcome['refused'], self::QUARANTINE_DIRECTORY, self::QUARANTINE_DISK));

        return self::SUCCESS;
    }

    /**
     * Soft-deleted media keeps its file on disk, so it is audited too.
     *
     * @return array{refused: int, unresolved: int}
     */
    private function scan(UploadContentPolicy $policy, bool $quarantine): array
    {
        $refused = 0;
        $unresolved = 0;

        foreach (Media::withTrashed()->orderBy('id')->lazyById(200) as $media) {
            $path = $media->getPathRelativeToRoot();
            $refusal = $this->refusalFor($policy, $media, $path);

            if ($refusal instanceof UploadContentRefusedException) {
                $refused++;
                $this->line(sprintf(
                    '  - #%d %s:%s (%s, owner %s #%s)',
                    $media->getKey(),
                    $media->disk,
                    $path,
                    $refusal->reason === UploadContentRefusedException::REASON_ACTIVE_SVG ? 'SVG is not a static drawing' : 'file type is not allowed',
                    (string) $media->model_type,
                    (string) $media->model_id,
                ));

                if ($quarantine && ! $this->quarantine($media, $path)) {
                    $unresolved++;
                }
            }
        }

        return ['refused' => $refused, 'unresolved' => $unresolved];
    }

    private function refusalFor(UploadContentPolicy $policy, Media $media, string $path): ?UploadContentRefusedException
    {
        if (! $media->isFileBacked()) {
            return null;
        }

        $disk = Storage::disk($media->disk);

        if (! $disk->exists($path)) {
            return null;
        }

        return $policy->refusalForStored((string) $media->file_name, static fn (): ?string => $disk->get($path));
    }

    /**
     * Copy first and verify, so a failed write never loses the only copy; the
     * served original is removed last.
     */
    private function quarantine(Media $media, string $path): bool
    {
        $source = Storage::disk($media->disk);
        $target = Storage::disk(self::QUARANTINE_DISK);
        $targetPath = self::QUARANTINE_DIRECTORY . '/' . $media->getKey() . '/' . basename((string) $media->file_name);

        try {
            $stream = $source->readStream($path);
            throw_unless(is_resource($stream), RuntimeException::class, 'The stored file could not be read.');

            try {
                throw_unless($target->writeStream($targetPath, $stream), RuntimeException::class, 'The quarantine copy could not be written.');
            } finally {
                fclose($stream);
            }

            throw_unless($target->size($targetPath) === $source->size($path), RuntimeException::class, 'The quarantine copy is incomplete.');
            throw_unless($source->delete($path), RuntimeException::class, 'The served file could not be removed.');
        } catch (RuntimeException $runtimeException) {
            $this->warn(sprintf('    not quarantined: %s', $runtimeException->getMessage()));

            return false;
        }

        if (! $media->trashed()) {
            $media->delete();
        }

        return true;
    }
}
