<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures\Livewire;

use Livewire\Component;

final class PublicCounterComponent extends Component
{
    public static int $increments = 0;

    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
        self::$increments++;
    }

    public function render(): string
    {
        return '<div>count: ' . $this->count . '</div>';
    }
}
