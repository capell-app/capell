<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('reports Eloquent models in public render data but not hydrated data or unmarked classes', function (): void {
    $root = dirname(__DIR__, 5);
    $fixture = $root . '/packages/core/tests/Unit/PHPStan/Fixtures/PublicRenderDataConsumer.php';
    $config = $root . '/packages/core/tests/Unit/PHPStan/Fixtures/public-render-data-rule.neon';
    $process = new Process([PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '--configuration=' . $config, $fixture], $root);
    $process->run();

    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $messages = $result['files'][$fixture]['messages'] ?? $result['error_details'][$fixture] ?? [];
    $reported = array_column(array_filter($messages, static fn (array $message): bool => ($message['identifier'] ?? null) === 'capell.publicRenderDataModel'), 'message');
    $text = implode("\n", $reported);

    expect($reported)->toHaveCount(2)
        ->and($text)->toContain('ModelCarryingRenderData::$site', 'ModelCarryingRenderData::$sites')
        ->not->toContain('HydratedRenderData')->not->toContain('UnmarkedModelHolder')->not->toContain('::$title');
});
