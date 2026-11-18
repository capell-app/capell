<?php

declare(strict_types=1);

use Capell\Core\Contracts\PublicRenderData;
use Capell\Core\Enums\AssetComponentEnum;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Frontend\Data\PublicHeaderData;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Frontend\Tests\Support\PublicWidgetResultsFixture;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\View;
use Livewire\Livewire;

require_once dirname(__DIR__, 5) . '/tests/Support/FrontendViewFixture.php';

it('preserves the header home link, translated name and media fallback', function (bool $withLogo): void {
    $fixture = frontendViewFixture();
    $site = new Site(['name' => 'Public site', 'meta' => ['logo_blade_view' => false]]);
    resolve(FrontendState::class)->withSite($site);
    $site->setRelation('translation', new Translation(['title' => 'News & events']));
    $domain = new SiteDomain(['scheme' => 'https', 'domain' => 'news.test', 'path' => '/en']);
    $site->setRelation('siteDomain', $domain);
    $site->setRelation('logo', $withLogo ? $fixture['media'] : null);

    $html = Blade::render('<x-capell::header.index />');
    $dom = frontendRenderedDom($html);
    expect(domAttribute(domElement($dom, '//header//a'), 'href'))->toBe($domain->url);
    if ($withLogo) {
        $image = domElement($dom, '//header//a/img');
        expect($image->getAttribute('src'))->toBe($fixture['media']->getFullUrl())
            ->and($image->getAttribute('alt'))->toBe('Public image')
            ->and($image->getAttribute('loading'))->toBe('eager')
            ->and(domCount($dom, '//header//a/span'))->toBe(0);
    } else {
        expect(trim(domText(domElement($dom, '//header//a/span'))))->toBe('News & events')
            ->and(domCount($dom, '//header//img'))->toBe(0);
    }
})->with([false, true]);

it('preserves the listing status and translated empty state', function (): void {
    $page = frontendViewFixture()['page'];
    $page->setRelation('translation', new Translation(['meta' => ['no_results' => 'No matches for this listing']]));

    $dom = frontendRenderedDom(Livewire::test(PublicWidgetResultsFixture::class)->html());
    expect(trim(domText(domElement($dom, '//*[@role="status"]'))))->toStartWith('0 ')
        ->and(trim(domText(domElement($dom, '//*[contains(@class, "capell-no-results")]'))))->toBe('No matches for this listing');
});

it('renders records using the selected result component', function (): void {
    $page = frontendViewFixture()['page'];
    $page->meta = ['component_item' => AssetComponentEnum::Tile->value];

    $dom = frontendRenderedDom(Livewire::test(PublicWidgetResultsFixture::class, ['withResult' => true])->html());
    expect(trim(domText(domElement($dom, '//*[@role="status"]'))))->toStartWith('1 ')
        ->and(trim(domText(domElement($dom, '//*[contains(@class, "capell-asset-tile")]//h3/a'))))->toBe('Public title')
        ->and(domCount($dom, '//*[contains(@class, "capell-no-results")]'))->toBe(0);
});

it('passes only projected public record data to the rendered view', function (string $viewName): void {
    frontendViewFixture();
    $payloads = [];
    $inspectedView = false;
    app()->resolving(PublicHeaderData::class, function (PublicHeaderData $data) use (&$payloads): void {
        $payloads[] = $data;
    });
    view()->composer($viewName, function (View $view) use (&$inspectedView, &$payloads): void {
        $inspectedView = true;
        foreach ($view->getData() as $value) {
            expect($value)->not->toBeInstanceOf(Model::class);
            if ($value instanceof PublicRenderData) {
                $payloads[] = $value;
            }
        }
    });

    if ($viewName === 'capell::components.header.index') {
        Blade::render('<x-capell::header.index />');
    } else {
        Livewire::test(PublicWidgetResultsFixture::class, ['withResult' => true])->html();
    }

    expect($inspectedView)->toBeTrue()
        ->and($payloads)->toHaveCount(1);
    foreach (get_object_vars($payloads[0]) as $field) {
        expect($field === null || is_scalar($field))->toBeTrue();
    }
})->with(['capell::components.header.index', 'capell::livewire.page.results']);
