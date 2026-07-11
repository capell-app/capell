<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\Pageable;
use Illuminate\Http\RedirectResponse;
use Spatie\LaravelData\Data;

final class PageResolutionData extends Data
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly ?Pageable $page,
        public readonly bool $shouldAbort404 = false,
        public readonly bool $attemptedWildcard = false,
        public readonly bool $isErrorPage = false,
        public readonly array $params = [],
        public readonly ?string $slug = null,
        public readonly ?string $routeUri = null,
        public readonly ?string $pageQuery = null,
        public readonly ?RedirectResponse $redirect = null,
    ) {}
}
