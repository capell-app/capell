<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Security;

use Illuminate\Console\Command;

/**
 * Replaces `config:clear` so a test can script its exit codes without
 * mocking the console kernel, which Testbench marks final.
 */
final class FakeConfigClearCommand extends Command
{
    /** @var list<int> */
    public static array $exitCodes = [];

    /** @var list<int> */
    public static array $calls = [];

    protected $signature = 'config:clear';

    protected $description = 'Scripted configuration cache clear for tests.';

    public function handle(): int
    {
        $exitCode = array_shift(self::$exitCodes) ?? 0;
        self::$calls[] = $exitCode;

        return $exitCode;
    }
}
