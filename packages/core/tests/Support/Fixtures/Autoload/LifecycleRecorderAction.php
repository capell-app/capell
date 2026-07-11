<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Fixtures\Autoload;

use Capell\Core\Contracts\PackageLifecycleAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;

final class LifecycleRecorderAction implements PackageLifecycleAction
{
    /** @var list<array{package: string, arguments: array<string, mixed>}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function handle(PackageData $package, array $arguments = [], ?ProgressReporter $reporter = null): void
    {
        self::$calls[] = [
            'package' => $package->name,
            'arguments' => $arguments,
        ];

        $reporter?->report('lifecycle action ran');
    }
}
