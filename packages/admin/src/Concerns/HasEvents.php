<?php

declare(strict_types=1);

namespace Capell\Admin\Concerns;

use Capell\Admin\Events\ServingAdmin;
use Closure;
use Illuminate\Support\Facades\Event;

// @codeCoverageIgnoreStart
trait HasEvents
{
    public function serving(Closure $callback): void
    {
        Event::listen(ServingAdmin::class, $callback);
    }
}

// @codeCoverageIgnoreEnd
