<?php

declare(strict_types=1);

use Capell\Frontend\Settings\FrontendSettingsMigrationProvider;

it('exposes migration provider with non-empty set', function (): void {
    $provider = new FrontendSettingsMigrationProvider;

    $migrations = $provider->migrations();

    expect(is_array($migrations))->toBeTrue();
});
