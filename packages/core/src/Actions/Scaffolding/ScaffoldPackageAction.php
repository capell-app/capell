<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Scaffolding;

use Capell\Core\Data\Scaffolding\PackageScaffoldInputData;
use Capell\Core\Enums\PackageScaffoldProfile;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Facades\File;
use JsonException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ScaffoldPackageAction
{
    use AsFake;
    use AsObject;

    /**
     * @throws JsonException
     */
    public function handle(PackageScaffoldInputData $input): void
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
    private function composerJson(PackageScaffoldInputData $input): array
    {
        $requires = [
            'capell-app/core' => '^1.0',
            'spatie/laravel-package-tools' => '^1.14',
        ];

        if ($input->profile === PackageScaffoldProfile::Full) {
            $requires['capell-app/admin'] = '^1.0';
            $requires['capell-app/frontend'] = '^1.0';
        }

        return [
            'name' => $input->packageName,
            'description' => $input->displayName . ' for Capell.',
            'type' => 'library',
            'require' => $requires,
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
                        $input->namespace . '\\Providers\\PackageServiceProvider',
                    ],
                ],
            ],
            'minimum-stability' => 'stable',
            'prefer-stable' => true,
        ];
    }

    private function renderStubDirectory(PackageScaffoldInputData $input): void
    {
        $stubDirectory = dirname(__DIR__, 3) . '/stubs/extension/' . $input->profile->value;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stubDirectory));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($stubDirectory) + 1);
            $targetPath = $input->targetPath . '/' . preg_replace('/\.stub$/', '', $relativePath);

            File::ensureDirectoryExists(dirname((string) $targetPath));
            File::put((string) $targetPath, $this->renderStub($file->getPathname(), $input));
        }
    }

    private function renderStub(string $path, PackageScaffoldInputData $input): string
    {
        return str_replace(
            array_keys($input->stubReplacements()),
            array_values($input->stubReplacements()),
            (string) file_get_contents($path),
        );
    }
}
