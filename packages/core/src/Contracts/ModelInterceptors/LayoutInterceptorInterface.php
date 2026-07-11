<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\ModelInterceptors;

use Capell\Core\Models\Layout;

interface LayoutInterceptorInterface
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeCreate(array $data): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function afterCreated(Layout $layout, array $data): void;
}
