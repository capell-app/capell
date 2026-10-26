<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Media;

use Spatie\LaravelData\Data;

final class MediaUploadAttemptData extends Data
{
    /** @param list<array{disk: string, directory: string, existingFiles: list<string>}> $destinations */
    public function __construct(
        public readonly int $mediaId,
        public readonly array $destinations,
        public readonly ?string $pathGenerator = null,
    ) {}
}
