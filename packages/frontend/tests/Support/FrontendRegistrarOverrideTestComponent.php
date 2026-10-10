<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Livewire\Component;

final class FrontendRegistrarOverrideTestComponent extends Component
{
    public function render(): string
    {
        return '<div></div>';
    }
}
