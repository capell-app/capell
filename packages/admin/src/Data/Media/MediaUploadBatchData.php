<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Media;

use Capell\Admin\Enums\MediaUploadStatus;
use Spatie\LaravelData\Data;

final class MediaUploadBatchData extends Data
{
    /** @param list<MediaUploadFileData> $files */
    public function __construct(
        public readonly string $id,
        public readonly int $siteId,
        public readonly string $ownerScope,
        public readonly array $files,
    ) {}

    public function successfulCount(): int
    {
        return count(array_filter($this->files, static fn (MediaUploadFileData $file): bool => $file->status === MediaUploadStatus::Successful));
    }

    public function hasRetryableFiles(): bool
    {
        return $this->successfulCount() < count($this->files);
    }

    public function hasWarnings(): bool
    {
        if ($this->hasRetryableFiles()) {
            return true;
        }

        return array_filter($this->files, static fn (MediaUploadFileData $file): bool => $file->reason !== 'capell-admin::media.upload_file_successful') !== [];
    }

    public function withFile(int $index, MediaUploadFileData $file): self
    {
        $files = $this->files;
        $files[$index] = $file;

        return new self($this->id, $this->siteId, $this->ownerScope, array_values($files));
    }
}
