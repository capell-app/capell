<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Process\ArtisanProcessEnvironment;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

class InstallFilamentPanelAction
{
    use AsObject;

    private const array THEME_METHODS = [
        'viteTheme',
        'theme',
        'colors',
        'darkMode',
        'brandLogo',
        'favicon',
        'font',
    ];

    public function __construct(
        private readonly ProcessFactoryInterface $processFactory,
    ) {}

    public function handle(ProgressReporter $reporter): void
    {
        $panelProviderPaths = $this->panelProviderPaths();

        if ($panelProviderPaths !== []) {
            $reporter->report('→ Filament admin panel already configured.');
            $this->reportMissingThemeConfiguration($panelProviderPaths, $reporter);

            return;
        }

        if (! array_key_exists('filament:install', Artisan::all())) {
            $this->installPanelInFreshProcess($reporter, null);
            $this->ensurePanelProviderWasCreated();
            $this->reportMissingThemeConfiguration($this->panelProviderPaths(), $reporter);

            return;
        }

        $reporter->step('Setting up Filament admin panel…');

        try {
            Artisan::call('filament:install', [
                '--panels' => true,
                '--no-interaction' => true,
            ]);
        } catch (Throwable $throwable) {
            $reporter->error(sprintf('✗ Failed to scaffold Filament panel: %s', $throwable->getMessage()));
            $this->installPanelInFreshProcess($reporter, $throwable);
        }

        $output = trim(Artisan::output());
        if ($output !== '') {
            $reporter->report($output);
        }

        $this->ensurePanelProviderWasCreated();
        $this->reportMissingThemeConfiguration($this->panelProviderPaths(), $reporter);
    }

    private function installPanelInFreshProcess(ProgressReporter $reporter, ?Throwable $previous): void
    {
        $reporter->step('Setting up Filament admin panel in a fresh Artisan process…');

        $process = $this->processFactory->make(
            [
                PHP_BINARY,
                'artisan',
                'filament:install',
                '--panels',
                '--no-interaction',
            ],
            base_path(),
            ArtisanProcessEnvironment::prepare(ComposerProcessEnvironment::forInstall($_SERVER)),
        );
        $process->setTimeout(300);
        $process->run(function (string $type, string $buffer) use ($reporter): void {
            foreach (explode("\n", trim($buffer)) as $line) {
                if ($line !== '') {
                    $reporter->report($line);
                }
            }
        });

        if ($process->isSuccessful()) {
            return;
        }

        $errorOutput = trim($process->getErrorOutput());
        $output = trim($process->getOutput());
        $message = $errorOutput !== '' ? $errorOutput : ($output !== '' ? $output : 'Unknown error.');

        throw new RuntimeException(
            sprintf('Failed to scaffold Filament panel: %s', $message),
            previous: $previous,
        );
    }

    private function ensurePanelProviderWasCreated(): void
    {
        if ($this->panelProviderPaths() !== []) {
            return;
        }

        throw new RuntimeException(
            'Filament panel installation did not create an AdminPanelProvider. Run `php artisan filament:install --panels` manually, then rerun `php artisan capell:install`.',
        );
    }

    /**
     * @return array<int, string>
     */
    private function panelProviderPaths(): array
    {
        $providersDir = app_path('Providers/Filament');

        if (! is_dir($providersDir)) {
            return [];
        }

        $paths = glob($providersDir . '/*PanelProvider.php');

        if ($paths === false) {
            return [];
        }

        return array_values(array_filter($paths, is_file(...)));
    }

    /**
     * @param  array<int, string>  $panelProviderPaths
     */
    private function reportMissingThemeConfiguration(array $panelProviderPaths, ProgressReporter $reporter): void
    {
        if ($panelProviderPaths === [] || $this->hasThemeConfiguration($panelProviderPaths)) {
            return;
        }

        $reporter->report('→ Filament panel theme is not configured. Add ->viteTheme(...) or another theme configuration to your panel provider.');
    }

    /**
     * @param  array<int, string>  $panelProviderPaths
     */
    private function hasThemeConfiguration(array $panelProviderPaths): bool
    {
        foreach ($panelProviderPaths as $panelProviderPath) {
            $contents = file_get_contents($panelProviderPath);

            if (! is_string($contents)) {
                continue;
            }

            foreach (self::THEME_METHODS as $method) {
                if (preg_match('/->\s*' . preg_quote($method, '/') . '\s*\(/', $contents) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
