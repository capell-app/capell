<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Contracts\Pageable;

final readonly class PageUrlsRewritten
{
    /**
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     * @param  array<int, array{old: string, new: string}>  $urlChanges
     * @param  array<int, array<int, array{old: string, new: string}>>  $descendantUrlChanges
     */
    public function __construct(
        public Pageable $page,
        public array $urlChanges = [],
        public array $descendantUrlChanges = [],
        public bool $automaticRedirectsAllowed = true,
    ) {}
}
