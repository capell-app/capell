<?php

declare(strict_types=1);

use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Support\Stubs\FakeMigrationFilesystem;
use Illuminate\Support\Facades\File;

it('runs install command and does not publish files for capell:publish-migrations', function (): void {
    $fakeFileManager = new FakeMigrationFilesystem([
        'fileExists' => [],
        'isDir' => [],
    ]);

    app()->instance(MigrationFilesystemInterface::class, $fakeFileManager);

    artisanCommand('capell:frontend-install')
        ->assertExitCode(0);

    expect($fakeFileManager->calls)->not()->toContain(fn (array $call): bool => $call[0] === 'copy');
});

it('generates frontend tailwind assets during the non-interactive install lifecycle', function (): void {
    $assetPath = sys_get_temp_dir() . '/capell-frontend-install-' . uniqid() . '/frontend.css';

    app()->bind('capell.tailwind.generator', fn (): object => new readonly class($assetPath)
    {
        public function __construct(private string $assetPath) {}

        /** @return list<string> */
        public function generate(?string $outputPath = null): array
        {
            $path = $outputPath ?? $this->assetPath;
            File::ensureDirectoryExists(dirname($path));
            File::put($path, '/* generated during install */');

            return [$path];
        }
    });

    try {
        artisanCommand('capell:frontend-install', ['--no-interaction' => true])
            ->expectsOutputToContain('Generated Tailwind assets at: ' . $assetPath)
            ->assertExitCode(0);

        expect(File::get($assetPath))->toBe('/* generated during install */');
    } finally {
        File::deleteDirectory(dirname($assetPath));
    }
});
