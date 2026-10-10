<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Capell\Frontend\Livewire\Page\AbstractPage;
use Illuminate\Contracts\View\View;
use Override;

final class FrontendLocaleTestPage extends AbstractPage
{
    public ?string $localeProbe = null;

    public function captureLocaleProbe(): void
    {
        $this->localeProbe = app()->getLocale();
    }

    #[Override]
    public function render(): View
    {
        return view()->file(__DIR__ . '/../Fixtures/livewire-context-test.blade.php');
    }
}
