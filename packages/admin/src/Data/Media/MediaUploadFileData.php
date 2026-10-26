<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Media;

use Capell\Admin\Enums\MediaUploadStatus;
use Spatie\LaravelData\Data;

final class MediaUploadFileData extends Data
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly MediaUploadStatus $status = MediaUploadStatus::Unresolved,
        public readonly string $reason = 'capell-admin::media.upload_not_attempted',
        public readonly ?int $mediaId = null,
        public readonly ?MediaUploadAttemptData $attempt = null,
        public readonly ?string $temporaryUpload = null,
    ) {}
}
