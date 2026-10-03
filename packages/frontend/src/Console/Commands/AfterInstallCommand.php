<?php

declare(strict_types=1);

namespace Capell\Frontend\Console\Commands;

use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Support\Install\ConsoleProgressReporter;
use Capell\Frontend\Actions\ApplyFrontendInstallationAction;
use Capell\Frontend\Actions\PrepareFrontendInstallationAction;
use Capell\Frontend\Actions\ResolveFrontendDependencyPlanAction;
use Capell\Frontend\Data\Assets\FrontendDependencyPlanData;
use Capell\Frontend\Exceptions\FrontendResourcePlanException;
use Capell\Frontend\Support\Assets\FrontendViteInputRegistry;
use Illuminate\Console\Command;

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
        $viteIntegrated = resolve(PrepareFrontendInstallationAction::class)->viteInputHelperIsIntegrated();
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

        try {
            ApplyFrontendInstallationAction::run($dependencyPlan, (bool) $this->option('dev'), new ConsoleProgressReporter($this));
        } catch (FrontendResourcePlanException $frontendResourcePlanException) {
            $this->error($frontendResourcePlanException->getMessage());

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
}
