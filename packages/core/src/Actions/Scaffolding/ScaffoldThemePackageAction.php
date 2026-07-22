<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Scaffolding;

use Capell\Core\Data\Scaffolding\ThemeScaffoldInputData;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Facades\File;
use JsonException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ScaffoldThemePackageAction
{
    use AsFake;
    use AsObject;

    /**
     * @throws JsonException
     */
    public function handle(ThemeScaffoldInputData $input): void
    {
        File::ensureDirectoryExists($input->targetPath);

        File::put(
            $input->targetPath . '/composer.json',
            JsonCodec::encode($this->composerJson($input), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
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
                'capell-app/core' => '^1.0',
                'capell-app/frontend' => '^1.0',
                'capell-app/theme-foundation' => '^1.0',
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
            'require-dev' => [
                'orchestra/testbench' => '^11.0',
                'pestphp/pest' => '^4.1',
                'pestphp/pest-plugin-laravel' => '^4.0',
            ],
            'scripts' => [
                'test' => 'pest',
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
