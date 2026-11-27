<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Diagnostics\BuildAgentDoctorReportAction;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Console\Command;

final class AgentDoctorCommand extends Command
{
    protected $signature = 'capell:agent:doctor {--json : Output the report as JSON}';

    protected $description = 'Show what an AI agent needs to build on this site: packages, themes, page types, widgets, makers and MCP status';

    public function handle(): int
    {
        $report = BuildAgentDoctorReportAction::run();

        if ($this->option('json')) {
            $this->line(JsonCodec::encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line(sprintf('Capell %s on Laravel %s, PHP %s', $report->versions['capell'] ?? 'unknown', $report->versions['laravel'], $report->versions['php']));
        $this->newLine();

        $this->line('Packages');
        $this->table(['Package', 'Version', 'Installed'], array_map(
            fn (array $package): array => [$package['name'], $package['version'] ?? '-', $package['installed'] ? 'yes' : 'no'],
            $report->packages,
        ));

        $this->line('Themes');
        $this->table(['Key', 'Extends', 'Package', 'Installed'], array_map(
            fn (array $theme): array => [$theme['key'], $theme['extends'] ?? '-', $theme['package'], $theme['installed'] ? 'yes' : 'no'],
            $report->themes,
        ));

        $this->line('Page types: ' . ($report->pageTypes === [] ? 'none' : implode(', ', array_column($report->pageTypes, 'name'))));
        $this->line('Widgets and blocks: ' . ($report->widgets === [] ? 'none registered' : implode(', ', array_column($report->widgets, 'key'))));
        $this->line('Makers: ' . implode(', ', array_column($report->makers, 'key')));
        $this->newLine();

        $this->line('MCP: ' . $report->mcp['status'] . ($report->mcp['nextStep'] !== null ? ' (run ' . $report->mcp['nextStep'] . ')' : ''));
        $this->newLine();

        $this->line('Commands to start with');
        foreach ($report->commands as $command) {
            $this->line(sprintf('  %s  %s', $command['command'], $command['purpose']));
        }

        $this->line('Run with --json for the same report as JSON.');

        return self::SUCCESS;
    }
}
