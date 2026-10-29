<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

use Capell\Core\Data\Install\InstallHandoffData;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

final class InstallCommandPresenter
{
    /**
     * @return array<int, string>
     */
    public function introDetails(
        bool $freshInstall,
        bool $forceFreshInstall,
        bool $demo,
        bool $planOnly,
        bool $noSideEffects,
    ): array {
        $details = [];

        if ($freshInstall) {
            $details[] = $forceFreshInstall
                ? 'a forced fresh database refresh'
                : 'a fresh database refresh';
        }

        if ($demo) {
            $details[] = 'demo content';
        }

        if ($planOnly) {
            $details[] = 'a plan-only preview';
        }

        if ($noSideEffects) {
            $details[] = 'side effects disabled';
        }

        return $details;
    }

    public function outputHandoff(
        InstallHandoffData $handoff,
        bool $wroteJson,
        OutputStyle $output,
        Factory $components,
    ): void {
        $output->newLine();
        $title = $handoff->status === 'completed'
            ? __('capell-core::install.handoff.completed_title')
            : __('capell-core::install.handoff.incomplete_title');
        $output->writeln('<fg=blue;options=bold>' . OutputFormatter::escape($title) . '</>');
        $output->newLine();

        $components->twoColumnDetail(
            'Selected packages',
            $handoff->selectedPackages === [] ? 'None' : implode(', ', $handoff->selectedPackages),
        );
        $components->twoColumnDetail('Migrations', $handoff->outcomes['migrations']);
        $components->twoColumnDetail('Setup', $handoff->outcomes['setup']);
        $components->twoColumnDetail('Doctor', $handoff->outcomes['doctor']);
        $components->twoColumnDetail('Admin URL', $handoff->urls['admin'] ?? 'Unavailable');
        $components->twoColumnDetail('Public URL', $handoff->urls['public']);
        $components->twoColumnDetail('First page', $handoff->firstPage['status']);
        $components->twoColumnDetail('Warnings', (string) ($handoff->warningCount ?? count($handoff->warnings)));

        if ($handoff->warnings !== []) {
            $output->newLine();
            $output->writeln('<options=bold>' . __('capell-core::install.handoff.warnings_title') . '</>');
            foreach ($handoff->warnings as $warning) {
                $output->writeln('  • ' . OutputFormatter::escape($warning));
            }
        }

        $this->outputTips($handoff, $output);

        $output->writeln('Next: ' . $handoff->nextAction['label'] . ' — ' . $handoff->nextAction['url']);
        $output->writeln($handoff->publicImpact['summary']);
        $output->writeln('No Capell account connection or telemetry identity submission is required for this handoff.');

        if ($wroteJson) {
            $output->writeln('<info>Machine-readable install handoff written.</info>');
        }
    }

    public function renderFailure(Throwable $throwable, OutputStyle $output): void
    {
        $message = trim($throwable->getMessage());

        $output->newLine();
        $output->writeln('<error>Capell installation failed.</error>');

        if ($message !== '') {
            $output->writeln($message);
        }

        $output->writeln('Set CAPELL_INSTALL_DEBUG=1 for step-level diagnostics. Review the failed step before continuing.');
    }

    private function outputTips(InstallHandoffData $handoff, OutputStyle $output): void
    {
        if ($handoff->tips === []) {
            return;
        }

        $output->newLine();
        $output->writeln('<options=bold>' . __('capell-core::install.handoff.title') . '</>');

        $width = max(array_map(fn (array $tip): int => mb_strlen($tip['label']), $handoff->tips));

        foreach ($handoff->tips as $index => $tip) {
            $output->writeln(sprintf(
                '  %d. %s  %s',
                $index + 1,
                OutputFormatter::escape(mb_str_pad($tip['label'], $width)),
                OutputFormatter::escape($tip['detail']),
            ));
        }

        $output->newLine();
    }
}
