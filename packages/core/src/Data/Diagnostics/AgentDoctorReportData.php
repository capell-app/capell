<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Override;
use Spatie\LaravelData\Data;

/**
 * Everything an agent needs to orient itself in a Capell site with one call: what is installed,
 * what it can build with and which command to run next.
 */
final class AgentDoctorReportData extends Data
{
    public const int SchemaVersion = 1;

    /**
     * @param  array{capell: ?string, laravel: string, php: string}  $versions
     * @param  list<array{name: string, version: ?string, installed: bool, type: string, tier: ?string}>  $packages
     * @param  list<array{key: string, name: string, extends: ?string, package: string, runtime: string, installed: bool}>  $themes
     * @param  list<array{name: string, label: string, model: ?string}>  $pageTypes
     * @param  list<array{type: string, key: string, package: string}>  $widgets
     * @param  list<array{key: string, label: string, description: string, command: string, writesPhp: bool, writesDatabase: bool}>  $makers
     * @param  array{laravelMcp: bool, agentBridge: bool, status: string, nextStep: ?string}  $mcp
     * @param  list<array{command: string, purpose: string}>  $commands
     */
    public function __construct(
        public readonly array $versions,
        public readonly array $packages,
        public readonly array $themes,
        public readonly array $pageTypes,
        public readonly array $widgets,
        public readonly array $makers,
        public readonly array $mcp,
        public readonly array $commands,
    ) {}

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'capell-agent-doctor' => self::SchemaVersion,
            'versions' => $this->versions,
            'packages' => $this->packages,
            'themes' => $this->themes,
            'pageTypes' => $this->pageTypes,
            'widgets' => $this->widgets,
            'makers' => $this->makers,
            'mcp' => $this->mcp,
            'commands' => $this->commands,
        ];
    }
}
