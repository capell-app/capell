<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Capell\Core\Contracts\Pageable;
use Capell\Frontend\Livewire\Page\AbstractPage;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Contracts\View\View;
use Override;

final class FrontendContextTestPage extends AbstractPage
{
    /** @var array<string, int|null> */
    public array $contextProbe = [];

    /** @var array<string, int|null> */
    public array $setupProbe = [];

    #[Override]
    protected function setup(): void
    {
        $this->setupProbe = $this->readContext();
    }

    public function captureContextProbe(): void
    {
        $this->contextProbe = $this->readContext();
    }

    public function tryCaptureContextProbe(): void
    {
        $this->contextProbe = ['restored' => resolve(FrontendState::class)->page() instanceof Pageable ? 1 : 0];
    }

    #[Override]
    public function render(): View
    {
        return view()->file(__DIR__ . '/../Fixtures/livewire-context-test.blade.php');
    }

    /** @return array<string, int|null> */
    private function readContext(): array
    {
        $state = resolve(FrontendState::class);
        $page = $state->page();

        return [
            'page_id' => $page instanceof Pageable ? (int) $page->getKey() : null,
            'site_id' => $state->site()?->getKey(),
            'language_id' => $state->language()?->getKey(),
            'layout_id' => $state->layout()?->getKey(),
            'theme_id' => $state->theme()?->getKey(),
        ];
    }
}
