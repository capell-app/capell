<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('cancels superseded quality runs but never a required merge-group run', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/code-quality-and-styling.yml');

    expect($workflow['concurrency']['group'])->toBe('php-quality-${{ github.ref }}')
        ->and($workflow['concurrency']['cancel-in-progress'])->toBe("\${{ github.event_name != 'merge_group' }}");
});
