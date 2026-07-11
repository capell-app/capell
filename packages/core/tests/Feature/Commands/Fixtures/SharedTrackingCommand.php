<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Commands\Fixtures;

use Illuminate\Console\Command;

class SharedTrackingCommand extends Command
{
    /** @var list<string> */
    public static array $executionOrder = [];

    public function __construct(string $signature)
    {
        $this->signature = $signature;

        parent::__construct();
    }

    public static function reset(): void
    {
        self::$executionOrder = [];
    }

    public function handle(): int
    {
        self::$executionOrder[] = $this->getName() ?? $this->signature;

        return Command::SUCCESS;
    }
}
