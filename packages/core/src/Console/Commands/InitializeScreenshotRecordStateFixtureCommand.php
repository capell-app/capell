<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Support\Screenshots\RecordStateScreenshotFixture;
use Illuminate\Console\Command;
use Throwable;

final class InitializeScreenshotRecordStateFixtureCommand extends Command
{
    protected $signature = 'capell:screenshot-record-state-fixture
        {--force : Confirm this is an intentional disposable screenshot seed}';

    protected $description = 'Seed record-state data for an explicit disposable screenshot run';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to seed screenshot fixtures without --force.');

            return self::FAILURE;
        }

        try {
            RecordStateScreenshotFixture::initialize();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Record-state screenshot fixture initialized.');

        return self::SUCCESS;
    }
}
