<?php

declare(strict_types=1);

namespace Capell\Core\Octane;

interface Resettable
{
    public const string TAG = 'octane.resettable';

    public function flushOctaneState(): void;
}
