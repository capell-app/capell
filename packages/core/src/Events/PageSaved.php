<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Contracts\Pageable;

final class PageSaved
{
    /**
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     * @param  array<string, mixed>  $formData
     */
    public function __construct(
        public Pageable $page,
        public array $formData = [],
    ) {}
}
