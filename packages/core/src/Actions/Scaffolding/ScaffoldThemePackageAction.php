<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Scaffolding;

use Capell\Core\Data\Scaffolding\ThemeScaffoldInputData;
use Illuminate\Support\Facades\File;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ScaffoldThemePackageAction
{
    /**
     * @throws JsonException
     */
    public function handle(ThemeScaffoldInputData $input): void
    {
        File::ensureDirectoryExists($input->targetPath);

        File::put(
            $input->targetPath . '/composer.json',
            json_encode($this->composerJson($input), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        );

        $this->renderStubDirectory($input);
    }

    /**
     * @return array<string, mixed>
     */
    private function composerJson(ThemeScaffoldInputData $input): array
    {
        return [
            'name' => $input->packageName,
            'description' => $input->displayName . ' theme for Capell.',
            'type' => 'library',
            'require' => [
                'capell-app/core' => '^4.0',
                'capell-app/frontend' => '^4.0',
                'spatie/laravel-package-tools' => '^1.14',
            ],
            'autoload' => [
                'psr-4' => [
                    $input->namespace . '\\' => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    $input->namespace . '\\Tests\\' => 'tests/',
                ],
            ],
            'extra' => [
                'laravel' => [
                    'providers' => [
                        $input->namespace . '\\' . $input->providerClass(),
                    ],
                ],
            ],
            'minimum-stability' => 'stable',
            'prefer-stable' => true,
        ];
    }

    private function renderStubDirectory(ThemeScaffoldInputData $input): void
    {
        $stubDirectory = dirname(__DIR__, 3) . '/stubs/theme/local';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stubDirectory));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($stubDirectory) + 1);
            $relativePath = str_replace('ThemeServiceProvider.php.stub', $input->providerClass() . '.php.stub', $relativePath);
            $targetPath = $input->targetPath . '/' . preg_replace('/\.stub$/', '', $relativePath);

            File::ensureDirectoryExists(dirname((string) $targetPath));
            File::put((string) $targetPath, $this->renderStub($file->getPathname(), $input));
        }
    }

    private function renderStub(string $path, ThemeScaffoldInputData $input): string
    {
        return str_replace(
            array_keys($input->stubReplacements()),
            array_values($input->stubReplacements()),
            (string) file_get_contents($path),
        );
    }
}
