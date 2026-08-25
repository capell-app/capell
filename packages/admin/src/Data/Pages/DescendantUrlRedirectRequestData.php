<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Capell\Core\Contracts\Pageable;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

/** @template TPageModel of Model */
final class DescendantUrlRedirectRequestData extends Data
{
    /**
     * @param  Pageable<TPageModel>  $page  The edited ancestor page.
     * @param  array<int, array<int, string>>  $submittedUrls  Descendant page id => language id => old URL.
     * @param  array<int, array<int, string>>  $expectedUrls  Descendant page id => language id => old URL.
     */
    public function __construct(
        public readonly Pageable $page,
        public readonly array $submittedUrls,
        public readonly array $expectedUrls,
    ) {}
}
