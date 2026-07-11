<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Configurators;

interface MutatesCreateData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateFormDataBeforeCreate(array $data): array;
}
