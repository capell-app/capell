<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Livewire\Component;

final class LivewireCompatibilityTestComponent extends Component
{
    public function render(): string
    {
        return '<div></div>';
    }
}
