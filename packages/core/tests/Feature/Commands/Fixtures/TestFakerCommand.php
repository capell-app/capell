<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Commands\Fixtures;

use Illuminate\Console\Command;

class TestFakerCommand extends Command
{
    public static int $lastCount = 0;

    /**
     * @var array<int, string>|null
     */
    public static ?array $lastSites = null;

    /**
     * @var array<int, string>|null
     */
    public static ?array $lastLanguages = null;

    protected $signature = 'test:faker {--count=25} {--sites=*} {--languages=*} {--force}';

    public function handle(): int
    {
        self::$lastCount = (int) $this->option('count');

        $sites = $this->option('sites');
        self::$lastSites = is_array($sites) && $sites !== [] ? $sites : null;

        $languages = $this->option('languages');
        self::$lastLanguages = is_array($languages) && $languages !== [] ? $languages : null;

        return Command::SUCCESS;
    }
}
