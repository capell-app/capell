<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Actions\Runtime\BuildRuntimeRoleProviderManifestsAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Patching\PhpFileEditor;
use Capell\Core\Support\Process\ArtisanProcessEnvironment;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Runtime\RuntimeRoleCachePaths;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\PanelRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use RuntimeException;
use Throwable;

class InstallFilamentPanelAction
{
    use AsFake;
    use AsObject;

    private const array THEME_METHODS = [
        'viteTheme',
        'theme',
    ];

    public function __construct(
        private readonly ProcessFactoryInterface $processFactory,
    ) {}

    public static function registerPanelProviders(): void
    {
        foreach (self::panelProviderPaths() as $path) {
            $relativePath = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $path);
            $class = 'App\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

            if (! class_exists($class)) {
                require_once $path;
            }

            if (! class_exists($class)) {
                continue;
            }

            // A provider the application already registered has contributed its panel,
            // through Filament's registry callback at bootstrap or an earlier pass here.
            // Rebuilding it after boot would replay panel extenders on a discarded
            // instance, which the installed panel runtime rejects as a topology change.
            if (app()->getProvider($class) !== null) {
                continue;
            }

            $provider = app()->register($class);

            if (! $provider instanceof PanelProvider) {
                continue;
            }

            if (! app()->resolved(PanelRegistry::class)) {
                continue;
            }

            $panel = $provider->panel(Panel::make());
            $registry = resolve(PanelRegistry::class);

            if ($registry->get($panel->getId()) === null) {
                $registry->register($panel);
            }
        }
    }

    public function handle(ProgressReporter $reporter): void
    {
        $this->installPanel($reporter);

        // Fresh Artisan children must see a newly scaffolded panel, including
        // retries whose role manifests were built before the panel existed.
        if (is_file(resolve(RuntimeRoleCachePaths::class)->metadata())) {
            BuildRuntimeRoleProviderManifestsAction::run();
        }
    }

    /**
     * @return array<int, string>
     */
    private static function panelProviderPaths(): array
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

    private function installPanel(ProgressReporter $reporter): void
    {
        $panelProviderPaths = self::panelProviderPaths();

        if ($panelProviderPaths !== []) {
            $reporter->report('→ Filament admin panel already configured.');
            $this->configurePanelThemes($panelProviderPaths, $reporter);

            return;
        }

        if (! array_key_exists('filament:install', Artisan::all())) {
            $this->installFilamentInFreshProcess($reporter, null);
            $this->ensurePanelProviderWasCreated();
            self::registerPanelProviders();
            $this->ensureDefaultThemeStylesheetExists();
            $this->configurePanelThemes(self::panelProviderPaths(), $reporter);

            return;
        }

        $reporter->step('Setting up Filament admin panel…');

        try {
            RunArtisanCommandAction::run('filament:install', [
                '--panels' => true,
                '--no-interaction' => true,
            ], $reporter);
        } catch (Throwable $throwable) {
            $reporter->error(sprintf('✗ Failed to scaffold Filament panel: %s', $throwable->getMessage()));
            $this->installFilamentInFreshProcess($reporter, $throwable);
        }

        $this->ensurePanelProviderWasCreated();
        self::registerPanelProviders();
        $this->ensureDefaultThemeStylesheetExists();
        $this->configurePanelThemes(self::panelProviderPaths(), $reporter);
    }

    private function installFilamentInFreshProcess(ProgressReporter $reporter, ?Throwable $previous): void
    {
        $reporter->step('Setting up Filament admin panel in a fresh Artisan process…');

        $command = [PHP_BINARY, 'artisan', 'filament:install'];

        // Asset publication can fail after scaffolding succeeds. Regenerating
        // that registered panel would remove its default and login configuration.
        if (self::panelProviderPaths() === []) {
            $command[] = '--panels';
        }

        $command[] = '--no-interaction';

        $process = $this->processFactory->make(
            $command,
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
        if (self::panelProviderPaths() !== []) {
            return;
        }

        throw new RuntimeException(
            'Filament panel installation did not create an AdminPanelProvider. Run `php artisan filament:install --panels` manually, then rerun `php artisan capell:install`.',
        );
    }

    private function ensureDefaultThemeStylesheetExists(): void
    {
        $themePath = resource_path('css/filament/admin/theme.css');

        if (File::exists($themePath)) {
            return;
        }

        File::ensureDirectoryExists(dirname($themePath));
        File::put($themePath, <<<'CSS'
@import '../../../../vendor/filament/filament/resources/css/theme.css';

@source '../../../../app/Filament/**/*';
@source '../../../../resources/views/filament/**/*';
CSS);
    }

    /**
     * @param  array<int, string>  $panelProviderPaths
     */
    private function configurePanelThemes(array $panelProviderPaths, ProgressReporter $reporter): void
    {
        foreach ($panelProviderPaths as $path) {
            $editor = new PhpFileEditor($path);
            $method = $editor->findMethodInClass(pathinfo($path, PATHINFO_FILENAME), 'panel');

            if ($method?->stmts === null || count($method->stmts) !== 1) {
                $this->reportMissingThemeConfiguration([$path], $reporter);

                continue;
            }

            $statement = $method->stmts[0];
            if (! $statement instanceof Return_ || ! $statement->expr instanceof MethodCall) {
                $this->reportMissingThemeConfiguration([$path], $reporter);

                continue;
            }

            $call = $statement->expr;
            while ($call instanceof MethodCall) {
                if (! $call->name instanceof Identifier) {
                    continue 2;
                }

                if (in_array($call->name->toString(), self::THEME_METHODS, true)) {
                    continue 2;
                }

                $call = $call->var;
            }

            if (! $call instanceof Variable || $call->name !== 'panel') {
                $this->reportMissingThemeConfiguration([$path], $reporter);

                continue;
            }

            $this->ensureDefaultThemeStylesheetExists();
            $statement->expr = new MethodCall($statement->expr, 'viteTheme', [
                new Arg(new String_('resources/css/filament/admin/theme.css')),
            ]);
            $editor->save();
            $reporter->report('→ Configured the compiled Filament admin theme.');
        }
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
