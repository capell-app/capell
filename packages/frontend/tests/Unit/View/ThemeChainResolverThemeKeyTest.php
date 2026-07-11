<?php

declare(strict_types=1);

use Capell\Core\Support\Manifest\CapellManifestData;

it('uses explicit theme keys for theme packages named theme hyphen name', function (): void {
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'capell-app/theme-corporate',
        surfaces: ['frontend'],
        overrides: [
            'kind' => 'theme',
            'extends' => null,
            'themeKey' => 'corporate',
        ],
    ));

    expect($manifest->themeKey)->toBe('corporate')
        ->and($manifest->toArray()['themeKey'])->toBe('corporate');
});
