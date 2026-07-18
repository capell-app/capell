<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

final class LinkableContentData extends Data
{
    public function __construct(
        public string $type,
        public int $id,
        public string $label,
        public string $url,
        public bool $status,
        public int $site_id,
        public int $language_id,
    ) {}
}
