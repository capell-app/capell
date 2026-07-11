<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Support\Settings\Fixtures;

use Capell\Core\Contracts\SettingsContract;

class MockAdminSettingsWithSchema implements SettingsContract
{
    public static function group(): string
    {
        return 'admin';
    }

    public static function schema(): string
    {
        return MockAdminSchema::class;
    }
}
