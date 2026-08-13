<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\MarketplaceSmokeQa\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Command\Command as CommandAlias;

final class MarketplaceSmokeQaProbeCommand extends Command
{
    /** @var string */
    protected $signature = 'marketplace-smoke:probe
        {--expect-update : Require the version-two migration marker}
    ';

    /** @var string */
    protected $description = 'Verify the disposable Marketplace smoke extension provider and migrations.';

    public function handle(): int
    {
        $providerRegistered = app()->bound('capell.marketplace-smoke-qa.provider');
        $migrationPresent = Schema::hasTable('marketplace_smoke_qa_records');
        $updatePresent = Schema::hasColumn('marketplace_smoke_qa_records', 'update_marker');
        $passed = $providerRegistered
            && $migrationPresent
            && (! (bool) $this->option('expect-update') || $updatePresent);

        $this->line(json_encode([
            'provider_registered' => $providerRegistered,
            'migration_present' => $migrationPresent,
            'update_migration_present' => $updatePresent,
        ], JSON_THROW_ON_ERROR));

        return $passed ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
    }
}
