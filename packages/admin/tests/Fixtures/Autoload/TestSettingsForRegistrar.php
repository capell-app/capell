<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Core\Contracts\SettingsContract;

final class TestSettingsForRegistrar implements SettingsContract
{
    public static function group(): string
    {
        return 'admin-bridge-registrar-test';
    }
}
