<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Configurators;

class InvalidConfigurator
{
    public static function getKey(): string
    {
        return 'Invalid';
    }
}
