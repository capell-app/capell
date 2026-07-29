<?php

declare(strict_types=1);

it('keeps the Rector cache inside the current checkout', function (): void {
    $configuration = file_get_contents(dirname(__DIR__, 2) . '/rector.php');

    expect($configuration)
        ->not->toBeFalse()
        ->toContain("cacheDirectory: \$rectorCacheDirectory . '/files'")
        ->toContain('containerCacheDirectory: $rectorCacheDirectory')
        ->toContain("\$rectorCacheDirectory = __DIR__ . '/var/rector'")
        ->not->toContain("cacheDirectory: '/tmp/")
        ->not->toContain("containerCacheDirectory: '/tmp/");
});
