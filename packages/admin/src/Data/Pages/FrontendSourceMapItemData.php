<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Spatie\LaravelData\Data;

final class FrontendSourceMapItemData extends Data
{
    public function __construct(
        public string $typeLabel,
        public string $preview,
        public string $modelReference,
        public string $fieldPath,
        public ?string $editUrl = null,
        public bool $visible = true,
    ) {}
}
