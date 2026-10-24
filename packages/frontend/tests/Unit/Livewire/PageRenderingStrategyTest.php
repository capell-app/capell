<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Themes\LayoutViewRegistry;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Livewire\Page\Page as LivewirePage;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Tests\Support\ScriptFixture;
use Illuminate\Support\Facades\Route;

it('disables Livewire assets for blade only pages', function (): void {
    $page = Page::factory()->make(['meta' => null]);

    expect(renderedPageRequiresLivewire($page))->toBeFalse();
});

it('enables Livewire assets for pages with islands', function (): void {
    $page = Page::factory()->make([
        'meta' => ['rendering_strategy' => RenderingStrategyEnum::BladeWithIslands->value],
    ]);

    expect(renderedPageRequiresLivewire($page))->toBeTrue();
});

it('enables Livewire assets for pages with a loaded blueprint strategy', function (): void {
    $blueprint = Blueprint::factory()->make([
        'meta' => ['rendering_strategy' => RenderingStrategyEnum::BladeWithIslands->value],
    ]);
    $page = Page::factory()
        ->make(['meta' => null])
        ->setRelation('blueprint', $blueprint);

    expect(renderedPageRequiresLivewire($page))->toBeTrue();
});

it('enables Livewire assets for loaded Livewire page blueprints', function (): void {
    $blueprint = Blueprint::factory()->make([
        'is_livewire' => true,
        'meta' => ['rendering_strategy' => RenderingStrategyEnum::BladeOnly->value],
    ]);
    $page = Page::factory()
        ->make(['meta' => null])
        ->setRelation('blueprint', $blueprint);

    expect(renderedPageRequiresLivewire($page))->toBeTrue();
});

it('does not lazy load the page type while deciding Livewire assets', function (): void {
    // Error-page status has its own blueprint lookup; this case isolates layout asset selection.
    $page = new class(['meta' => null]) extends Page
    {
        #[Override]
        public function getMorphClass(): string
        {
            return (new Page)->getMorphClass();
        }

        #[Override]
        public function isErrorPage(): bool
        {
            return false;
        }
    };

    expect($page->relationLoaded('blueprint'))->toBeFalse()
        ->and(renderedPageRequiresLivewire($page))->toBeFalse()
        ->and($page->relationLoaded('blueprint'))->toBeFalse();
});

function renderedPageRequiresLivewire(Page $page): bool
{
    $fixture = new ScriptFixture;
    $fixture->write('page.blade.php', '<div>Page content</div>');
    // A host layout consumes this flag to decide whether to include Livewire assets.
    $fixture->write('layout.blade.php', <<<'BLADE'
<div data-assets="{{ $livewireEnabled ? 'livewire' : 'blade' }}">{{ $slot }}</div>
BLADE);
    view()->addNamespace('strategy-test', $fixture->root);
    resolve(LayoutViewRegistry::class)->registerMaster('strategy-test::page', 'Fixture page');
    resolve(LayoutViewRegistry::class)->registerLayout('strategy-test::layout', 'Fixture layout');

    $page->setRelation('translation', null);
    resolve(FrontendState::class)->withPage($page)
        ->withSite(new Site)->withTheme(new Theme)->withLanguage(new Language(['code' => 'en']))
        ->withLayout(new Layout(['meta' => [
            'master_file' => 'strategy-test::page',
            'layout_file' => 'strategy-test::layout',
        ]]));
    Route::get('/__strategy-test', LivewirePage::class);

    try {
        $response = test()->get('/__strategy-test')->assertOk()->assertSee('Page content');

        return str_contains((string) $response->getContent(), 'data-assets="livewire"');
    } finally {
        $fixture->close();
    }
}
