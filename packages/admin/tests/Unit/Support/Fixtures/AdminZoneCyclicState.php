<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Support\Fixtures;

final class AdminZoneCyclicState
{
    public ?self $child = null;
}
