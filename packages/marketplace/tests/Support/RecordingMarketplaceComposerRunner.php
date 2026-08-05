<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests\Support;

use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Data\MarketplaceComposerResultData;

/**
 * A composer runner that records what it was asked to install instead of
 * shelling out. Per-instance state, so one test can never read another's calls.
 */
final class RecordingMarketplaceComposerRunner implements MarketplaceComposerRunner
{
    /** @var list<array{name: string, constraint: string}> */
    public array $calls = [];

    public function __construct(private readonly string $output = '') {}

    public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
    {
        $this->calls[] = ['name' => $composerName, 'constraint' => $versionConstraint];

        return new MarketplaceComposerResultData(exitCode: 0, output: $this->output, errorOutput: '');
    }

    public function ran(): bool
    {
        return $this->calls !== [];
    }
}
