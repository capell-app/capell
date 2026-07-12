<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Support\Security\PublicHtmlSafetyInspector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Stress the public render pipeline at both ends of page complexity:
 *  - a minimal "simple" page, and
 *  - a "kitchen sink" page whose HTML content is a large, varied document
 *    (headings, paragraphs, lists, tables, figures, blockquotes, anchors)
 *    across many blocks.
 *
 * Both render anonymously and must stay within a bounded query budget, ship no
 * client runtime, and never leak an authoring surface into public HTML.
 */
it('renders a simple frontend page within a tight query budget and with no client runtime', function (): void {
    seedFrontendStressPage(
        domain: 'simple-stress.test',
        slug: '/simple-stress-page',
        title: 'Simple stress page',
        content: '<p>Single content block for the simple stress page.</p>',
    );

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $response = $this->get('http://simple-stress.test/simple-stress-page');

    $response
        ->assertOk()
        ->assertSee('Simple stress page')
        ->assertSee('Single content block', false)
        ->assertDontSee('wire:navigate', false)
        ->assertDontSee('Alpine.data', false)
        ->assertDontSee('window.beaconData', false)
        ->assertDontSee('/livewire/', false);

    expect($queryCount)->toBeLessThan(25)
        ->and(resolve(PublicHtmlSafetyInspector::class)->containsAuthoringSurface((string) $response->getContent()))
        ->toBeFalse();
})->group('frontend', 'stress');

it('renders a kitchen-sink frontend page with bounded queries and no authoring surface leakage', function (): void {
    $blockCount = 60;

    seedFrontendStressPage(
        domain: 'kitchen-sink-stress.test',
        slug: '/kitchen-sink-stress-page',
        title: 'Kitchen sink stress page',
        content: kitchenSinkContent($blockCount),
    );

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $response = $this->get('http://kitchen-sink-stress.test/kitchen-sink-stress-page');

    $response
        ->assertOk()
        ->assertSee('Kitchen sink stress page')
        ->assertSee('Kitchen sink block 1', false)
        ->assertSee(sprintf('Kitchen sink block %d', $blockCount), false)
        ->assertDontSee('wire:navigate', false)
        ->assertDontSee('Alpine.data', false)
        ->assertDontSee('window.beaconData', false)
        ->assertDontSee('/livewire/', false);

    $content = (string) $response->getContent();

    // A vastly larger document must not cost more queries than the simple page — render
    // size is content-driven, not extra database work.
    expect($queryCount)->toBeLessThan(25)
        ->and(resolve(PublicHtmlSafetyInspector::class)->containsAuthoringSurface($content))->toBeFalse();
})->group('frontend', 'stress');

function kitchenSinkContent(int $blockCount): string
{
    $blocks = [];

    foreach (range(1, $blockCount) as $index) {
        $blocks[] = <<<HTML
        <section id="block-{$index}">
            <h2>Kitchen sink block {$index}</h2>
            <p>Rich paragraph {$index} stressing the public render pipeline with prose content.</p>
            <ul>
                <li>Item {$index}.1</li>
                <li>Item {$index}.2</li>
                <li>Item {$index}.3</li>
            </ul>
            <table>
                <thead><tr><th>Column A</th><th>Column B</th></tr></thead>
                <tbody><tr><td>Cell {$index}A</td><td>Cell {$index}B</td></tr></tbody>
            </table>
            <blockquote>Pull quote {$index} for the kitchen sink page.</blockquote>
            <figure>
                <img src="https://cdn.example.test/kitchen-sink-{$index}.jpg" alt="Image {$index}" loading="lazy" width="640" height="360">
                <figcaption>Figure caption {$index}</figcaption>
            </figure>
            <p><a href="/kitchen-sink-stress-page#block-{$index}">Jump to block {$index}</a></p>
        </section>
        HTML;
    }

    return implode("\n", $blocks);
}

/**
 * Wire a single, anonymously renderable HTML page on its own domain.
 *
 * @return array{language: Language, page: Page, site: Site}
 */
function seedFrontendStressPage(
    string $domain,
    string $slug,
    string $title,
    string $content,
): array {
    config()->set('capell-frontend.html_cache', false);
    config()->set('capell-frontend.write_html_cache', false);
    config()->set('capell-frontend.minify_html', true);
    config()->set('capell-frontend.render_html_content_with_blade', false);
    Cache::flush();

    $language = Language::factory()->createOne(['code' => 'en']);
    $layout = Layout::factory()->default()->create();

    $site = Site::factory()
        ->language($language)
        ->withTranslations(
            languages: $language,
            data: ['title' => $title . ' site'],
            siteDomainData: [
                'default' => true,
                'domain' => $domain,
                'scheme' => 'http',
                'path' => null,
            ],
        )
        ->create();

    $type = Blueprint::factory()->page()->create([
        'key' => str($title)->slug()->toString() . '-pages',
        'meta' => ['content_structure' => 'html'],
    ]);

    $page = Page::factory()
        ->site($site)
        ->blueprint($type)
        ->layout($layout)
        ->withTranslations(
            languages: $language,
            data: ['title' => $title, 'content' => $content],
            slug: $slug,
        )
        ->create();

    return ['language' => $language, 'page' => $page, 'site' => $site];
}
