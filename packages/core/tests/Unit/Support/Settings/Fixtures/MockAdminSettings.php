<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Support\Settings\Fixtures;

use Capell\Core\Contracts\SettingsContract;

class MockAdminSettings implements SettingsContract
{
    public static function group(): string
    {
        return 'admin';
    }
}
