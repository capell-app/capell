<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PublicWidgetResultsFixture extends Component
{
    /** @return Collection<int, never> */
    #[Computed]
    public function results(): Collection
    {
        return collect();
    }

    public function render(): View
    {
        return view('capell::livewire.page.results');
    }
}
