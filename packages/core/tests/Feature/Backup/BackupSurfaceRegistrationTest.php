<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('registers backup configuration and operator commands', function (): void {
    $commands = Artisan::all();

    expect(config('backup'))->toBeArray()
        ->and(config('backup.enabled'))->toBeFalse()
        ->and(config('backup.prefix'))->toBe('capell-backups')
        ->and(config('backup.max_age_hours'))->toBe(26)
        ->and(config('backup.minimum_retained'))->toBe(7)
        ->and(config('backup.retain'))->toBe(30)
        ->and(config('backup.process_timeout_seconds'))->toBe(3600)
        ->and($commands)->toHaveKeys([
            'capell:backup:create',
            'capell:backup:health',
            'capell:backup:prune',
            'capell:backup:restore',
        ])
        ->and($commands['capell:doctor']->getDefinition()->hasOption('connection'))->toBeTrue()
        ->and($commands['capell:doctor']->getDefinition()->hasOption('database'))->toBeTrue();
});
