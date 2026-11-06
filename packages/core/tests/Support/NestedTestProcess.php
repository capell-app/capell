<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Symfony\Component\Process\Process;

final class NestedTestProcess
{
    /** @param list<string> $command */
    public static function make(array $command, ?string $cwd = null): Process
    {
        $environment = [];
        foreach (array_keys(getenv() + $_ENV + $_SERVER) as $key) {
            if (in_array($key, ['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN'], true)
                || preg_match('/^(DB_|REDIS|CACHE|QUEUE|SESSION)/', (string) $key) === 1) {
                $environment[$key] = false;
            }
        }

        $environment['DB_CONNECTION'] = 'sqlite';
        $environment['DB_DATABASE'] = ':memory:';

        return new Process($command, $cwd, $environment);
    }
}
