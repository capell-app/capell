<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Media;

interface MediaUploadMetadataResolver
{
    /** @return array{height: int, width: int} */
    public function resolve(string $path): array;
}
