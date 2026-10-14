<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Actions\Runtime\BuildRuntimeRoleProviderManifestsAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\PanelThemeTargetResolver;
use Capell\Core\Support\Patching\PatchStatus;
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
use Override;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use RuntimeException;
use Throwable;

class InstallFilamentPanelAction
{
    use AsFake;
    use AsObject;

    private const string DEFAULT_THEME_PATH = 'resources/css/filament/admin/theme.css';

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

        InstallRunState::retireScaffoldedPanelProviders();
    }

    public function configureThemeForProvider(string $path, ProgressReporter $reporter): void
    {
        $this->configurePanelThemes(resolve(PanelThemeTargetResolver::class)->resolve($path), $reporter);
    }

    public function themePatchStatus(string $path): PatchStatus
    {
        try {
            $editor = new PhpFileEditor($path);
            $themeCalls = $this->themeCalls($editor, $path);

            if ($themeCalls !== []) {
                return $this->referencesDefaultTheme($themeCalls) && ! File::exists(resource_path('css/filament/admin/theme.css'))
                    ? PatchStatus::Applicable
                    : PatchStatus::AlreadyApplied;
            }

            return $this->canAppendTheme($editor, $path) ? PatchStatus::Applicable : PatchStatus::Customised;
        } catch (RuntimeException) {
            return PatchStatus::Unsupported;
        }
    }

    /**
     * @return list<string>
     */
    private static function panelProviderPaths(): array
    {
        return resolve(PanelThemeTargetResolver::class)->providerPaths();
    }

    private function installPanel(ProgressReporter $reporter): void
    {
        $panelProviderPaths = self::panelProviderPaths();

        if ($panelProviderPaths !== []) {
            $reporter->report('→ Filament admin panel already configured.');
            $ownedPaths = resolve(PanelThemeTargetResolver::class)->resolve();
            $this->configurePanelThemes($ownedPaths, $reporter);
            $this->reportMissingThemeConfiguration(array_values(array_diff($panelProviderPaths, $ownedPaths)), $reporter);

            return;
        }

        if (! array_key_exists('filament:install', Artisan::all())) {
            $this->installFilamentInFreshProcess($reporter, null);
            $this->ensurePanelProviderWasCreated();
            self::registerPanelProviders();
            $this->configurePanelThemes(resolve(PanelThemeTargetResolver::class)->resolve(), $reporter);

            return;
        }

        $reporter->step('Setting up Filament admin panel…');

        try {
            try {
                RunArtisanCommandAction::run('filament:install', [
                    '--panels' => true,
                    '--no-interaction' => true,
                ], $reporter);
            } finally {
                // Scaffolding may succeed before asset publication interrupts installation.
                InstallRunState::recordScaffoldedPanelProviders(self::panelProviderPaths());
            }
        } catch (Throwable $throwable) {
            $reporter->error(sprintf('✗ Failed to scaffold Filament panel: %s', $throwable->getMessage()));
            $this->installFilamentInFreshProcess($reporter, $throwable);
        }

        $this->ensurePanelProviderWasCreated();
        self::registerPanelProviders();
        $this->configurePanelThemes(resolve(PanelThemeTargetResolver::class)->resolve(), $reporter);
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

        $existingPaths = self::panelProviderPaths();
        try {
            $process->run(function (string $type, string $buffer) use ($reporter): void {
                foreach (explode("\n", trim($buffer)) as $line) {
                    if ($line !== '') {
                        $reporter->report($line);
                    }
                }
            });
        } finally {
            InstallRunState::recordScaffoldedPanelProviders(array_values(array_diff(self::panelProviderPaths(), $existingPaths)));
        }

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
            $themeCalls = $this->themeCalls($editor, $path);

            if ($themeCalls !== []) {
                if ($this->referencesDefaultTheme($themeCalls)) {
                    $this->ensureDefaultThemeStylesheetExists();
                }

                continue;
            }

            if (! $this->canAppendTheme($editor, $path)) {
                $this->reportMissingThemeConfiguration([$path], $reporter);

                continue;
            }

            $wasScaffolded = in_array(str($path)->after(base_path() . DIRECTORY_SEPARATOR)->toString(), InstallRunState::scaffoldedPanelProviders(), true);
            $method = $editor->findMethodInClass(pathinfo($path, PATHINFO_FILENAME), 'panel');
            $statement = $method?->stmts[0] ?? null;
            throw_unless($statement instanceof Return_ && $statement->expr instanceof MethodCall, RuntimeException::class, 'Unable to append panel theme.');

            $this->ensureDefaultThemeStylesheetExists();
            $statement->expr = new MethodCall($statement->expr, 'viteTheme', [
                new Arg(new String_(self::DEFAULT_THEME_PATH)),
            ]);
            $editor->save();

            if ($wasScaffolded) {
                InstallRunState::recordScaffoldedPanelProviders([$path]);
            }

            $reporter->report('→ Configured the compiled Filament admin theme.');
        }
    }

    /** @return list<MethodCall> */
    private function themeCalls(PhpFileEditor $editor, string $path): array
    {
        $method = $editor->findMethodInClass(pathinfo($path, PATHINFO_FILENAME), 'panel');
        $panelArgument = $method->params[0]->var ?? null;
        $argumentName = $panelArgument instanceof Variable && is_string($panelArgument->name) ? $panelArgument->name : null;
        $visitor = new class($argumentName, self::THEME_METHODS) extends NodeVisitorAbstract
        {
            /** @var array<int, MethodCall> */
            public array $calls = [];

            /** @param list<string> $themeMethods */
            public function __construct(
                private readonly ?string $argumentName,
                private readonly array $themeMethods,
            ) {}

            #[Override]
            public function enterNode(Node $node): ?int
            {
                // Deferred callbacks and nested declarations do not configure this panel.
                if ($node instanceof FunctionLike || $node instanceof ClassLike) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                $call = $node instanceof Return_ ? $node->expr : $node;
                $calls = [];

                while ($call instanceof MethodCall) {
                    if ($call->name instanceof Identifier && in_array($call->name->toString(), $this->themeMethods, true)) {
                        $calls[] = $call;
                    }

                    $call = $call->var;
                }

                if ($node instanceof Return_ || ($call instanceof Variable && $this->argumentName !== null && $call->name === $this->argumentName)) {
                    foreach ($calls as $themeCall) {
                        $this->calls[spl_object_id($themeCall)] = $themeCall;
                    }
                }

                return null;
            }
        };
        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse($method->stmts ?? []);

        return array_values($visitor->calls);
    }

    /** @param list<MethodCall> $calls */
    private function referencesDefaultTheme(array $calls): bool
    {
        return array_any($calls, static fn (MethodCall $call): bool => ($call->args[0] ?? null) instanceof Arg
            && $call->args[0]->value instanceof String_
            && $call->args[0]->value->value === self::DEFAULT_THEME_PATH);
    }

    private function canAppendTheme(PhpFileEditor $editor, string $path): bool
    {
        $method = $editor->findMethodInClass(pathinfo($path, PATHINFO_FILENAME), 'panel');

        if ($method?->stmts === null || count($method->stmts) !== 1) {
            return false;
        }

        $statement = $method->stmts[0];
        $call = $statement instanceof Return_ ? $statement->expr : null;

        if (! $call instanceof MethodCall) {
            return false;
        }

        while ($call instanceof MethodCall) {
            if (! $call->name instanceof Identifier) {
                return false;
            }

            $call = $call->var;
        }

        return $call instanceof Variable && $call->name === 'panel';
    }

    /**
     * @param  array<int, string>  $panelProviderPaths
     */
    private function reportMissingThemeConfiguration(array $panelProviderPaths, ProgressReporter $reporter): void
    {
        foreach ($panelProviderPaths as $path) {
            if (! $this->hasThemeConfiguration($path)) {
                $reporter->report('→ Filament panel theme is not configured. Add ->viteTheme(...) or another theme configuration to your panel provider.');
            }
        }
    }

    private function hasThemeConfiguration(string $panelProviderPath): bool
    {
        try {
            return $this->themeCalls(new PhpFileEditor($panelProviderPath), $panelProviderPath) !== [];
        } catch (RuntimeException) {
            return false;
        }
    }
}
