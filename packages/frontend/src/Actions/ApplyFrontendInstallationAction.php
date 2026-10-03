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
        foreach ([$plan->runtimeCommand, $plan->developmentCommand] as $command) {
            if ($command !== []) {
                $this->runCommand($command, $reporter, 'Capell remediation: resolve the dependency constraints reported above, then re-run the exact command from the plan.');
            }
        }

        $this->runCommand([$plan->manager->value, 'run', $development ? 'dev' : 'build'], $reporter, 'Capell remediation: install the planned dependencies, verify the generated input manifest, and re-run the displayed build command.');
    }

    /** @param list<string> $command */
    private function runCommand(array $command, ?ProgressReporter $reporter, string $remediation): void
    {
        $result = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)->run($command);
        foreach ([$result->output(), $result->errorOutput()] as $output) {
            if ($output !== '') {
                $reporter?->report($output);
            }
        }

        throw_unless($result->successful(), FrontendResourcePlanException::class, $remediation);
    }
}
