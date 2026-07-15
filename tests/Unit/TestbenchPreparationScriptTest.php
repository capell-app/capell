<?php

declare(strict_types=1);

it('stages the committed frontend build for isolated Testbench workers', function (): void {
    $script = file_get_contents(dirname(__DIR__, 2) . '/scripts/prepare-testbench-vendor-configs.php');

    expect($script)
        ->toBeString()
        ->toContain("'packages/frontend/publishes/build' => 'public/vendor/capell-frontend'");
});
