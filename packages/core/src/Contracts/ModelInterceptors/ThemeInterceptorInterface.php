<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\ModelInterceptors;

use Capell\Core\Models\Theme;

interface ThemeInterceptorInterface
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeCreate(array $data): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function afterCreated(Theme $theme, array $data): void;
}
