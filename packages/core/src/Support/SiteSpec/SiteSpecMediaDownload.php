<?php

declare(strict_types=1);

namespace Capell\Core\Support\SiteSpec;

use Capell\Core\Enums\MediaCollectionEnum;

final readonly class SiteSpecMediaDownload
{
    public function __construct(
        public string $path,
        public string $fileName,
        public string $sourceOrigin,
        public string $sourceHash,
        public MediaCollectionEnum $collection,
        public ?string $pageSlug = null,
    ) {}
}
