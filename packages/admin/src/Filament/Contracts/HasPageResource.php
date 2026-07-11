<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Contracts;

interface HasPageResource
{
    public static function getResource(): string;
}
