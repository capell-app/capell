<?php

declare(strict_types=1);

use Capell\Core\Actions\ProjectBuild\ReadProjectBuildManifestAction;
use Capell\Core\Contracts\ProjectBuild\ProjectBuildManifestMigration;
use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Capell\Core\Support\ProjectBuild\ProjectBuildManifestMigrationRegistry;
use Capell\Core\Tests\Support\ProjectBuildManifestFixture;
use Illuminate\Validation\ValidationException;

final class VersionZeroProjectBuildManifestMigration implements ProjectBuildManifestMigration
{
    public function fromVersion(): int
    {
        return 0;
    }

    public function toVersion(): int
    {
        return 1;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function migrate(array $payload): array
    {
        $payload['schemaVersion'] = 1;
        unset($payload['legacyVersion']);

        return $payload;
    }
}

it('reads a current manifest without migration', function (): void {
    $manifest = ReadProjectBuildManifestAction::run(json_encode(ProjectBuildManifestFixture::payload(), JSON_THROW_ON_ERROR));

    expect($manifest)->toBeInstanceOf(ProjectBuildManifestData::class)
        ->and($manifest->schemaVersion)->toBe(1);
});

it('migrates an explicitly supported legacy manifest before validation', function (): void {
    $registry = new ProjectBuildManifestMigrationRegistry;
    $registry->register(new VersionZeroProjectBuildManifestMigration);
    $payload = ProjectBuildManifestFixture::payload();
    $payload['schemaVersion'] = 0;
    $payload['legacyVersion'] = 'v0';
    app()->instance(ProjectBuildManifestMigrationRegistry::class, $registry);

    $manifest = ReadProjectBuildManifestAction::run(json_encode($payload, JSON_THROW_ON_ERROR));

    expect($manifest->schemaVersion)->toBe(1);
});

it('scopes the core-owned migration registry to the current operation', function (): void {
    $registry = resolve(ProjectBuildManifestMigrationRegistry::class);
    $registry->register(new VersionZeroProjectBuildManifestMigration);

    expect(resolve(ProjectBuildManifestMigrationRegistry::class))->toBe($registry);

    app()->forgetScopedInstances();

    $nextRegistry = resolve(ProjectBuildManifestMigrationRegistry::class);

    expect($nextRegistry)->not->toBe($registry)
        ->and(fn (): array => $nextRegistry->migrate(['schemaVersion' => 0], 1))
        ->toThrow(ValidationException::class, 'No compatible project build manifest migration');
});

it('refuses malformed, future, and migration-gap manifests', function (string $json, string $message): void {
    expect(fn (): mixed => ReadProjectBuildManifestAction::run($json))
        ->toThrow(ValidationException::class, $message);
})->with([
    'malformed JSON' => ['{', 'valid JSON'],
    'future version' => [json_encode(['schemaVersion' => 2], JSON_THROW_ON_ERROR), 'newer'],
    'migration gap' => [json_encode(['schemaVersion' => 0], JSON_THROW_ON_ERROR), 'migration'],
]);

it('rejects duplicate and non-forward migrations', function (): void {
    $registry = new ProjectBuildManifestMigrationRegistry;
    $registry->register(new VersionZeroProjectBuildManifestMigration);

    expect(function () use ($registry): void {
        $registry->register(new VersionZeroProjectBuildManifestMigration);
    })
        ->toThrow(LogicException::class, 'already registered');

    expect(function () use ($registry): void {
        $registry->register(new class implements ProjectBuildManifestMigration
        {
            public function fromVersion(): int
            {
                return 2;
            }

            public function toVersion(): int
            {
                return 2;
            }

            public function migrate(array $payload): array
            {
                return $payload;
            }
        });
    })->toThrow(LogicException::class, 'move forward');
});
