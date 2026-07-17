<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Events\FrontendContextResolved;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;

final class NotifySubscribersStep
{
    public function __construct(private readonly Dispatcher $events) {}

    public function handle(FrontendWork $work, Closure $next): mixed
    {
        $context = $work->context();
        $this->events->dispatch(new FrontendContextResolved($context));

        return $next($work);
    }
}
