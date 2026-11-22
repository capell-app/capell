<?php

declare(strict_types=1);

use Capell\Core\Data\Rendering\RenderHookContext;
use Capell\Frontend\Data\MainContentRenderHookData;
use Capell\Frontend\Data\PublicMainLayoutData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

require_once dirname(__DIR__, 5) . '/tests/Support/FrontendViewFixture.php';

it('preserves main layout HTML and slot ownership for each visitor', function (bool $signedIn, bool $hookOwnsSlot): void {
    if ($signedIn) {
        $visitor = new User(['name' => 'Private visitor']);
        $this->actingAs($visitor);
        $this->assertAuthenticatedAs($visitor);
    } else {
        $this->assertGuest();
    }

    $fixture = frontendViewFixture();
    $hooks = resolve(RenderHookRegistry::class);
    $hooks->register(RenderHookLocation::AfterContent, '<aside>After content</aside>');
    if ($hookOwnsSlot) {
        $hooks->registerCallable(RenderHookLocation::MainContent, function (RenderHookContext $context): string {
            $data = $context->item;
            expect($data)->toBeInstanceOf(MainContentRenderHookData::class);
            $data->slotRendered = true;

            return '<section>' . e($data->pageSlot) . '</section>';
        });
    }

    $render = static function () use ($fixture): string {
        $html = Blade::render('<x-capell::layout.main :page="$page" :layout="null" :theme="[]" :page-slot="$slotText" main-class="external" />', [
            ...$fixture, 'slotText' => 'Public & private <slot>',
        ]);

        return trim((string) preg_replace('/>\s+</', '><', $html));
    };

    $projected = false;
    view()->composer('capell::public.layout-main', function (View $view) use (&$projected): void {
        expect($view->getData()['renderData'])->toBeInstanceOf(PublicMainLayoutData::class);
        $projected = true;
    });
    $connection = DB::connection();
    $connection->enableQueryLog();
    try {
        $actual = $render();
        expect($projected)->toBeTrue()
            ->and($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
    }

    $base = dirname(__DIR__, 2) . '/Fixtures/stage-three-base-public-views';
    view()->prependNamespace('capell', $base);
    view()->getFinder()->flush();
    expect(view()->getFinder()->find('capell::components.layout.main'))->toStartWith($base . '/');
    expect($actual)->toBe($render())
        ->and(substr_count($actual, 'Public &amp; private &lt;slot&gt;'))->toBe(1)
        ->and($actual)->toContain('<aside>After content</aside>')
        ->and($actual)->not->toContain('Private visitor')
        ->and($actual)->not->toContain('data-capell-authoring');
})->with(['anonymous' => false, 'signed in' => true])
    ->with(['fallback' => false, 'hook renders slot' => true]);
