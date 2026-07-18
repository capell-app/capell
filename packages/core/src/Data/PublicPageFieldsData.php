<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

final class PublicPageFieldsData extends Data
{
    /**
     * @param  string|array<string, mixed>|null  $content
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public ?string $url = null,
        public ?string $title = null,
        public string|array|null $content = null,
        public array $meta = [],
    ) {}
}
