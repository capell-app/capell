<?php

declare(strict_types=1);

use Capell\Core\Support\Migration\MigrationFileScanner;
use Illuminate\Support\Facades\File;

afterEach(function (): void {
    Mockery::close();
});

it('returns sorted unique migration names including stubs by default', function (): void {
    File::shouldReceive('glob')
        ->once()
        ->with('/package/database/migrations/*.php')
        ->andReturn([
            '/package/database/migrations/2026_01_02_000000_second.php',
            '/package/database/migrations/2026_01_01_000000_first.php',
        ]);
    File::shouldReceive('glob')
        ->once()
        ->with('/package/database/migrations/*.php.stub')
        ->andReturn([
            '/package/database/migrations/2026_01_03_000000_third.php.stub',
            '/package/database/migrations/2026_01_01_000000_first.php.stub',
        ]);

    expect(MigrationFileScanner::names('/package/database/migrations'))->toBe([
        '2026_01_01_000000_first',
        '2026_01_02_000000_second',
        '2026_01_03_000000_third',
    ]);
});

it('can exclude stub migrations', function (): void {
    File::shouldReceive('glob')
        ->once()
        ->with('/package/database/migrations/*.php')
        ->andReturn(['/package/database/migrations/2026_01_01_000000_first.php']);
    File::shouldReceive('glob')
        ->never()
        ->with('/package/database/migrations/*.php.stub');

    expect(MigrationFileScanner::names('/package/database/migrations', includeStubs: false))->toBe([
        '2026_01_01_000000_first',
    ]);
});

it('returns an empty list when glob cannot read migration files', function (): void {
    File::shouldReceive('glob')->twice()->andReturn(false);

    expect(MigrationFileScanner::names('/unreadable'))->toBe([]);
});
