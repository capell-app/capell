<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('proposes refreshed shards from main without giving the test run any credentials', function (): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2) . '/.github/workflows/update-pest-shards.yml');
    $runner = $workflow['jobs']['update-shards'];
    $proposer = $workflow['jobs']['open-pull-request'];
    $uses = array_column($proposer['steps'], 'uses');

    expect($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and($workflow['on']['schedule'])->not->toBeEmpty()
        ->and(json_encode($runner))->not->toContain('secrets.')
        ->and($proposer['needs'])->toBe('update-shards')
        ->and($proposer['if'])->toContain("github.event_name != 'pull_request'")
        ->and(array_filter($proposer['steps'], static fn (array $step): bool => isset($step['run'])))->toBe([])
        ->and(array_filter($uses, static fn (string $action): bool => str_starts_with($action, 'actions/checkout@')))->toBe([]);
});
