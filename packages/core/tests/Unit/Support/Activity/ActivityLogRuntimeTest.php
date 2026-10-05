<?php

declare(strict_types=1);

use Capell\Tests\Fixtures\Activity\ContractActivity;
use Symfony\Component\Process\Process;

it('migrates and logs through a host-owned activity contract with the real vendor classes', function (): void {
    $root = dirname(__DIR__, 6);
    $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file=', $root . '/packages/core/tests/fixtures/activitylog-runtime.php', $root]);
    $process->mustRun();

    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $source = getenv('CAPELL_ACTIVITYLOG_SOURCE') ?: $root . '/vendor/spatie/laravel-activitylog';

    expect(realpath($result['source']))->toBe(realpath($source . '/src/Models/Activity.php'))
        ->and($result['core_source'])->toBe($root . '/packages/core/src/Support/Activity/ActivityLogCompat.php')
        ->and($result['model'])->toBe(ContractActivity::class)
        ->and($result['column'])->toBeTrue()
        ->and($result['old'])->toBe(['name' => 'Before'])
        ->and($result['new'])->toBe(['name' => 'After'])
        ->and($result['relation_count'])->toBe(2)
        ->and($result['hook'])->toBe('created');
});
