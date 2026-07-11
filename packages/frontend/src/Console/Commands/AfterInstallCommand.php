<?php

declare(strict_types=1);

namespace Capell\Frontend\Console\Commands;

use Capell\Core\Actions\RunNpmBuildAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Console\Commands\Concerns\PromptsWithOptionFallback;
use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\VendorAssetEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Frontend\Actions\GenerateTailwindAssetsAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;

use RuntimeException;

class AfterInstallCommand extends Command
{
    use DescribesCommandOptions;
    use PromptsWithOptionFallback;

    protected $signature = 'capell:frontend-after-install {--dev : Run the Vite dev build instead of production build}';

    protected $description = 'Run post-install steps for the Capell Frontend package (Tailwind assets + npm build)';

    public function handle(): int
    {
        $this->writeCommandIntro('run Capell Frontend post-install steps', $this->enabledOptionDetails([
            'dev' => 'development build mode',
        ]));

        $this->generateTailwindAssets();

        $this->installRequiredNpmDependencies();

        $this->runNpmBuildIfRequested();

        $this->info('Capell Frontend post-install steps completed successfully.');

        return self::SUCCESS;
    }

    private function generateTailwindAssets(): string
    {
        $results = GenerateTailwindAssetsAction::run();

        foreach ($results as $result) {
            $this->line(sprintf('Generated Tailwind assets at: %s', $result['path']));
        }

        $this->ensureGeneratedAssetsAreViteEntryPoints($results);

        return $results[0]['path'] ?? '';
    }

    /**
     * @param  array<int, array{path: string, content: string}>  $generatedAssets
     */
    private function ensureGeneratedAssetsAreViteEntryPoints(array $generatedAssets): void
    {
        if ($this->frontendAssetBuildTool() !== 'vite') {
            return;
        }

        $viteConfigPath = base_path('vite.config.js');

        if (! is_file($viteConfigPath)) {
            return;
        }

        $generatedAssetPaths = collect($generatedAssets)
            ->map(fn (array $asset): string => $this->relativePathForViteInput($asset['path']))
            ->filter(fn (string $path): bool => $path !== '')
            ->values();

        if ($generatedAssetPaths->isEmpty()) {
            return;
        }

        $config = file_get_contents($viteConfigPath);

        if ($config === false) {
            return;
        }

        $updatedConfig = preg_replace_callback(
            '/input:\s*\[(?<inputs>.*?)\]/s',
            function (array $matches) use ($generatedAssetPaths): string {
                $inputs = $matches['inputs'];
                $missingInputs = $generatedAssetPaths
                    ->reject(fn (string $path): bool => str_contains($inputs, "'" . $path . "'") || str_contains($inputs, '"' . $path . '"'))
                    ->values();

                if ($missingInputs->isEmpty()) {
                    return $matches[0];
                }

                $separator = str_contains($inputs, "\n") ? "\n" : ' ';
                $prefix = str_ends_with(rtrim($inputs), ',') ? '' : ',';
                $additions = $missingInputs
                    ->map(fn (string $path): string => $this->javascriptString($path))
                    ->implode(', ');

                return 'input: [' . rtrim($inputs) . $prefix . $separator . $additions . ']';
            },
            $config,
            1,
            $replacementCount,
        );

        if ($replacementCount !== 1 || ! is_string($updatedConfig) || $updatedConfig === $config) {
            return;
        }

        file_put_contents($viteConfigPath, $updatedConfig);

        $this->line('Updated Vite inputs for generated Capell frontend assets.');
    }

    private function frontendAssetBuildTool(): string
    {
        return config('capell-frontend.asset_build_tool', 'vite');
    }

    private function relativePathForViteInput(string $path): string
    {
        $basePath = Str::finish(base_path(), DIRECTORY_SEPARATOR);

        if (Str::startsWith($path, $basePath)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', Str::after($path, $basePath));
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }

    private function installRequiredNpmDependencies(): void
    {
        $npmDeps = $this->collectNpmDependencies();
        if ($npmDeps === []) {
            return;
        }

        $this->line('The following npm packages are required by installed Capell packages:');
        foreach ($npmDeps as $pkg => $version) {
            $this->line(sprintf('- %s@%s', $pkg, $version));
        }

        $this->requireInteractiveOrFail('npm packages installation confirmation', 'Run this command interactively to confirm npm installation.');

        $shouldInstall = confirm('Would you like to install these npm packages now?');
        if (! $shouldInstall) {
            return;
        }

        $packages = collect($npmDeps)
            ->map(fn (string $version, string $package): string => $this->npmPackageSpecifier($package, $version))
            ->values()
            ->all();

        $this->line('Running: npm install ' . implode(' ', $packages));
        $result = Process::run(['npm', 'install', ...$packages]);

        if ($result->successful()) {
            $this->info('npm packages installed successfully.');

            return;
        }

        $this->error('Failed to install npm packages.');
        $this->line($result->output());
    }

    private function runNpmBuildIfRequested(): void
    {
        $this->requireInteractiveOrFail('npm build confirmation', 'Run this command interactively to confirm the npm build.');

        $runBuild = confirm('Would you like to run an npm build after this command completes?', default: true);
        if (! $runBuild) {
            return;
        }

        $isDev = $this->option('dev') !== null && $this->option('dev') !== false;
        $command = $isDev ? 'npm run dev' : 'npm run build';

        $this->line('Running: ' . $command);

        try {
            RunNpmBuildAction::run($isDev);
            $this->info(($isDev ? 'Development' : 'Production') . ' build completed successfully.');
        } catch (RuntimeException $runtimeException) {
            $message = $runtimeException->getMessage();

            $this->error('npm build failed.');
            $this->line($message);

            foreach ($this->buildFailureHints($message) as $hint) {
                $this->newLine();
                $this->warn($hint);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function buildFailureHints(string $output): array
    {
        $hints = [];

        if (preg_match("/Can't resolve '(swiper\\/[^']+)'/", $output) === 1
            || str_contains($output, "Can't resolve 'swiper")
        ) {
            $hints[] = 'Hint: Swiper CSS paths failed to resolve. If Capell packages are symlinked via Composer path repositories, '
                . 'Tailwind v4 does not honor the Swiper package exports map. Import direct file paths '
                . '(e.g. `@import "swiper/swiper.css";`) or add Vite resolve.alias entries. '
                . 'See docs/tailwind-vendor-css.md.';
        }

        if (preg_match("/ENOENT: no such file or directory, open '([^']+)'/", $output) === 1) {
            $hints[] = 'Hint: A file referenced by the build could not be found. '
                . 'Run `npm install` and verify your `vite.config.js` path aliases.';
        }

        return $hints;
    }

    /**
     * @return array<string, string>
     */
    private function collectNpmDependencies(): array
    {
        $deps = [];

        CapellCore::getVendorAssetsForType(VendorAssetEnum::NpmDependency)
            ->filter(fn (VendorAssetData $asset): bool => $asset->packageName === null || CapellCore::isPackageInstalled($asset->packageName))
            ->each(function (VendorAssetData $asset) use (&$deps): void {
                $dependencyName = $asset->dependencyName();
                $dependencyVersion = $asset->dependencyVersion();

                if ($dependencyName === '' || ! is_string($dependencyVersion) || $dependencyVersion === '') {
                    return;
                }

                $deps[$dependencyName] = $dependencyVersion;
            });

        return $deps;
    }

    private function javascriptString(string $value): string
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '""';
    }

    private function npmPackageSpecifier(string $package, string $version): string
    {
        if (preg_match('/^(?:@[a-z0-9][a-z0-9._~-]*\/)?[a-z0-9][a-z0-9._~-]*$/', $package) !== 1) {
            throw new RuntimeException(sprintf('Invalid npm package name "%s".', $package));
        }

        if ($version === '' || preg_match('/[\s[:cntrl:]]/', $version) === 1) {
            throw new RuntimeException(sprintf('Invalid npm package version for "%s".', $package));
        }

        return sprintf('%s@%s', $package, $version);
    }
}
