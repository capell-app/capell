<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\Components\Headers;

use Closure;
use Illuminate\View\Component;

class CustomHeader extends Component
{
    public function render(): Closure
    {
        return static fn (): string => '<header>Custom Header</header>';
    }
}
