<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Settings\CoreSettings;

it('can access core settings via CapellCoreManager::settings()', function (): void {
    $settings = CapellCore::settings();

    expect($settings)->toBeInstanceOf(CoreSettings::class)
        ->and($settings->default_locale)->not()->toBeEmpty();
});
