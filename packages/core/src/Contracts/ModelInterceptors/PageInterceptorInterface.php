<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\ModelInterceptors;

use Capell\Core\Contracts\Pageable;
use Illuminate\Database\Eloquent\Model;

interface PageInterceptorInterface
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeCreate(array $data): array;

    /**
     * @param  Pageable<Model>  $page
     * @param  array<string, mixed>  $data
     */
    public function afterCreated(Pageable $page, array $data): void;
}
