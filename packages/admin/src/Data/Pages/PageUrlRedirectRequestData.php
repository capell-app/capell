<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Capell\Core\Contracts\Pageable;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

/** @template TPageModel of Model */
final class PageUrlRedirectRequestData extends Data
{
    /**
     * @param  Pageable<TPageModel>  $page
     * @param  array<int, string>  $submittedUrls
     * @param  array<int, string>  $expectedUrls
     */
    public function __construct(
        public readonly Pageable $page,
        public readonly array $submittedUrls,
        public readonly array $expectedUrls,
    ) {}
}
