<?php

declare(strict_types=1);

namespace Capell\Frontend\Console\Commands;

use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Support\Json\JsonCodec;
use Capell\Frontend\Actions\GenerateTailwindAssetsAction;
use Capell\Frontend\Actions\ResolveFrontendDependencyPlanAction;
use Capell\Frontend\Data\Assets\FrontendDependencyPlanData;
use Capell\Frontend\Support\Assets\FrontendViteInputRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;

final class AfterInstallCommand extends Command
{
    use DescribesCommandOptions;

    protected $signature = 'capell:frontend-after-install
        {--apply : Apply the printed plan in non-interactive mode}
        {--dev : Run the Vite development build instead of the production build}';

    protected $description = 'Plan or apply Capell frontend dependencies, Vite inputs, generated assets, and build';

    public function handle(): int
    {
        $dependencyPlan = ResolveFrontendDependencyPlanAction::run();
        $configuredInputs = [
            (string) config('capell-frontend.tailwind.output_css', 'resources/css/capell/frontend.css'),
            ...resolve(FrontendViteInputRegistry::class)->all(),
        ];
        $viteIntegrated = $this->viteInputHelperIsIntegrated();
        $buildCommand = [$dependencyPlan->manager->value, 'run', $this->option('dev') ? 'dev' : 'build'];

        $this->writeCommandIntro('plan Capell frontend installation', $this->enabledOptionDetails([
            'apply' => 'apply the installation plan',
            'dev' => 'development build mode',
        ]));
        $this->renderPlan($dependencyPlan, array_values(array_unique($configuredInputs)), $buildCommand, $viteIntegrated);

        if (! $this->input->isInteractive() && ! $this->option('apply')) {
            $this->comment('Report only: no frontend files, dependencies, or build outputs were changed. Re-run with --apply to apply this plan.');

            return self::SUCCESS;
        }

        if ($this->input->isInteractive() && ! confirm('Apply this frontend installation plan?', default: false)) {
            $this->comment('Frontend installation plan was not applied.');

            return self::SUCCESS;
        }

        if (! $viteIntegrated) {
            $this->error('Capell Vite inputs are not integrated. Add the exact snippet shown above before applying the plan.');

            return self::FAILURE;
        }

        if (! $this->runDependencyCommand($dependencyPlan->runtimeCommand) || ! $this->runDependencyCommand($dependencyPlan->developmentCommand)) {
            return self::FAILURE;
        }

        $generated = GenerateTailwindAssetsAction::run();
        $inputs = collect($generated)
            ->map(fn (array $asset): string => $this->relativeInputPath($asset['path']))
            ->merge(resolve(FrontendViteInputRegistry::class)->all())
            ->unique()
            ->sort()
            ->filter()
            ->values()
            ->all();
        $this->writeViteInputManifest($inputs);

        $build = Process::run($buildCommand);
        $this->outputProcessStreams($build->output(), $build->errorOutput());

        if (! $build->successful()) {
            $this->error('Capell remediation: install the planned dependencies, verify the generated input manifest, and re-run the displayed build command.');

            return self::FAILURE;
        }

        $this->info('Capell frontend installation plan applied successfully.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $viteInputs
     * @param  array<int, string>  $buildCommand
     */
    private function renderPlan(FrontendDependencyPlanData $plan, array $viteInputs, array $buildCommand, bool $viteIntegrated): void
    {
        $this->line('Frontend installation plan:');
        $this->line('- Package manager: ' . $plan->manager->value);
        $this->line('- Runtime dependencies: ' . ($plan->runtimeCommand === [] ? 'none' : implode(' ', $plan->runtimeCommand)));
        $this->line('- Development dependencies: ' . ($plan->developmentCommand === [] ? 'none' : implode(' ', $plan->developmentCommand)));
        $this->line('- Generated Vite inputs: ' . implode(', ', $viteInputs));
        $this->line('- Published assets: package-owned public/vendor files remain outside Vite');
        $this->line('- Build command: ' . implode(' ', $buildCommand));
        $this->line('- Vite input integration: ' . ($viteIntegrated ? 'present' : 'missing'));

        if (! $viteIntegrated) {
            $this->warn('Add this exact Vite integration:');
            $this->line("import { capellViteInputs } from './vendor/capell-app/frontend/resources/js/capell-vite-inputs.js'");
            $this->line('input: [...capellViteInputs(), /* application entries */]');
        }
    }

    /** @param  array<int, string>  $command */
    private function runDependencyCommand(array $command): bool
    {
        if ($command === []) {
            return true;
        }

        $result = Process::run($command);
        $this->outputProcessStreams($result->output(), $result->errorOutput());

        if ($result->successful()) {
            return true;
        }

        $this->error('Capell remediation: resolve the dependency constraints reported above, then re-run the exact command from the plan.');

        return false;
    }

    private function outputProcessStreams(string $output, string $errorOutput): void
    {
        if ($output !== '') {
            $this->getOutput()->write($output);
        }

        if ($errorOutput !== '') {
            $this->getOutput()->write($errorOutput);
        }
    }

    /** @param  array<int, string>  $inputs */
    private function writeViteInputManifest(array $inputs): void
    {
        $path = base_path('bootstrap/cache/capell-vite-inputs.json');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, JsonCodec::encode(['inputs' => $inputs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        $this->line('Generated Vite input manifest: bootstrap/cache/capell-vite-inputs.json');
    }

    private function viteInputHelperIsIntegrated(): bool
    {
        $path = base_path('vite.config.js');

        if (! is_file($path)) {
            return false;
        }

        $config = (string) file_get_contents($path);

        return str_contains($config, 'capellViteInputs') && str_contains($config, 'capell-vite-inputs.js');
    }

    private function relativeInputPath(string $path): string
    {
        $base = Str::finish(base_path(), DIRECTORY_SEPARATOR);

        return Str::startsWith($path, $base)
            ? str_replace(DIRECTORY_SEPARATOR, '/', Str::after($path, $base))
            : str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
