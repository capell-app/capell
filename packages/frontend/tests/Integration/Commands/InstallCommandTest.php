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
    $assetPath = base_path('resources/css/capell-install-test-' . uniqid() . '/frontend.css');
    $manifestPath = base_path('bootstrap/cache/capell-vite-inputs.json');
    File::delete($manifestPath);

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

        expect(File::get($assetPath))->toBe('/* generated during install */')
            ->and(json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR))->toBe([
                'inputs' => [
                    str_replace(DIRECTORY_SEPARATOR, '/', str_replace(base_path() . DIRECTORY_SEPARATOR, '', $assetPath)),
                ],
            ]);
    } finally {
        File::deleteDirectory(dirname($assetPath));
        File::delete($manifestPath);
    }
});
