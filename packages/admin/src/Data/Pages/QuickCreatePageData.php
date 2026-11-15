<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Spatie\LaravelData\Data;

final class QuickCreatePageData extends Data
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $slug,
        public readonly int $siteId,
        public readonly int $languageId,
        public readonly ?int $blueprintId,
        public readonly ?int $parentId,
        public readonly bool $publish,
    ) {}
}
