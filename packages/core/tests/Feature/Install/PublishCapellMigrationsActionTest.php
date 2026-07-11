<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\PublishCapellMigrationsAction;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Feature\Commands\Fixtures\FakeMigrationFilesystem;

it('publishes core migrations through the reusable migration publishing action', function (): void {
    $fakeFilesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    PublishCapellMigrationsAction::run(new NullProgressReporter);

    expect(collect($fakeFilesystem->calls)->contains(
        fn (array $call): bool => $call[0] === 'copy'
            && str_contains((string) $call[1], '/packages/core/database/migrations/')
            && str_contains((string) $call[2], '/database/migrations/'),
    ))->toBeTrue()
        ->and(collect($fakeFilesystem->calls)->contains(
            fn (array $call): bool => $call[0] === 'copy'
                && str_contains((string) $call[1], '/packages/core/database/settings/')
                && str_contains((string) $call[2], '/database/settings/'),
        ))->toBeTrue();
});

it('fails loudly when publishing core migrations fails', function (): void {
    $fakeFilesystem = new class extends FakeMigrationFilesystem
    {
        public function isWritable(string $path): bool
        {
            $this->calls[] = ['isWritable', $path];

            return false;
        }
    };

    app()->instance(MigrationFilesystemInterface::class, $fakeFilesystem);

    PublishCapellMigrationsAction::run(new NullProgressReporter);
})->throws(
    RuntimeException::class,
    'Failed publishing Capell migrations.',
);
