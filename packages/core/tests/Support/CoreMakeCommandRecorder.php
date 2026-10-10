<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

final class CoreMakeCommandRecorder
{
    /** @var list<string> */
    public array $paths = [];

    /** @var array<string, string> */
    public array $contents = [];
}
