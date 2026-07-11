<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Events\ServingCapell;
use Closure;
use Illuminate\Support\Facades\Event;

trait HasEvents
{
    public function serving(Closure $callback): void
    {
        Event::listen(ServingCapell::class, $callback);
    }
}
