<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Capell\Core\Contracts\Pageable;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

final class PageAuthoringInputData extends Data
{
    /**
     * @param  Pageable<Model>  $page
     * @param  array<string, mixed>  $formData
     * @param  array<int, string>  $previousUrls
     */
    public function __construct(
        public readonly Pageable $page,
        public readonly array $formData,
        public readonly array $previousUrls = [],
        public readonly bool $recordRedirects = false,
        public readonly bool $notifyCache = false,
    ) {}
}
