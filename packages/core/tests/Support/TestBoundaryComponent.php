<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Livewire\Component;

class TestBoundaryComponent extends Component
{
    public function render(): string
    {
        return '<div>Boundary probe</div>';
    }
}
