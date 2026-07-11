<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Commands\Fixtures;

use Illuminate\Console\Command;

class TestDemoCommand extends Command
{
    /** @var list<string> */
    public static array $executionOrder = [];

    /** @var array<string, mixed> */
    public static array $receivedOptions = [];

    protected $signature = 'test:demo {--url=} {--user=} {--languages=*} {--sites=*}';

    public static function reset(): void
    {
        self::$executionOrder = [];
        self::$receivedOptions = [];
    }

    public function handle(): int
    {
        self::$executionOrder[] = $this->getName() ?? 'test:demo';
        self::$receivedOptions = [
            'url' => $this->option('url'),
            'user' => $this->option('user'),
            'languages' => $this->option('languages'),
            'sites' => $this->option('sites'),
        ];

        return Command::SUCCESS;
    }
}
