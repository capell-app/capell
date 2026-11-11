<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Frontend\Data\Assets\FrontendDependencyPlanData;
use Capell\Frontend\Exceptions\FrontendResourcePlanException;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ApplyFrontendInstallationAction
{
    use AsFake;
    use AsObject;

    private const int PROCESS_TIMEOUT_SECONDS = 900;

    public function handle(FrontendDependencyPlanData $plan, bool $development = false, ?ProgressReporter $reporter = null): void
    {
        PrepareFrontendInstallationAction::run($plan, $reporter);
        $installed = false;
        foreach ([$plan->runtimeCommand, $plan->developmentCommand] as $command) {
            if ($command !== []) {
                $this->runCommand($command, $reporter, 'Capell remediation: resolve the dependency constraints reported above, then re-run the exact command from the plan.');
                $installed = true;
            }
        }

        // Every dependency can already be declared in package.json, which leaves
        // the plan with nothing to install. The build still needs node_modules.
        if (! $installed && ! is_dir(base_path('node_modules'))) {
            $this->runCommand([$plan->manager->value, 'install'], $reporter, 'Capell remediation: run the package manager install in the application root, then re-run the displayed build command.');
        }

        $this->runCommand([$plan->manager->value, 'run', $development ? 'dev' : 'build'], $reporter, 'Capell remediation: install the planned dependencies, verify the generated input manifest, and re-run the displayed build command.');
    }

    /** @param list<string> $command */
    private function runCommand(array $command, ?ProgressReporter $reporter, string $remediation): void
    {
        $result = Process::path(base_path())->timeout(self::PROCESS_TIMEOUT_SECONDS)->run($command);
        foreach ([$result->output(), $result->errorOutput()] as $output) {
            if ($output !== '') {
                $reporter?->report($output);
            }
        }

        throw_unless($result->successful(), FrontendResourcePlanException::class, $remediation);
    }
}
