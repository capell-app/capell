<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Capell\Core\Contracts\Pageable;
use Capell\Frontend\Contracts\FrontendContextReader;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PublicWidgetResultsFixture extends Component
{
    public bool $withResult = false;

    /** @return Collection<int, Pageable> */
    #[Computed]
    public function results(): Collection
    {
        $page = resolve(FrontendContextReader::class)->page();

        return collect($this->withResult && $page !== null ? [$page] : []);
    }

    public function render(): View
    {
        return view('capell::livewire.page.results');
    }
}
