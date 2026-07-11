<?php

declare(strict_types=1);

use Capell\Admin\Enums\CapellPermission;

it('publishes every default-format Capell custom permission in Shield config', function (): void {
    config()->set('filament-shield.permissions.case', 'pascal');
    config()->set('filament-shield.permissions.separator', ':');

    $config = require dirname(__DIR__, 3) . '/publishes/config/filament-shield.php';

    expect($config['custom_permissions'])->toEqualCanonicalizing(CapellPermission::names());
});
