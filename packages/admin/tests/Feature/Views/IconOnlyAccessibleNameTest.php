<?php

declare(strict_types=1);

use Capell\Core\Data\AssetData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\ComponentAttributeBag;

it('names the asset controls in the rendered page assets column', function (): void {
    $assets = collect([
        new AssetData('pages', Model::class, 'Pages', 'heroicon-o-document'),
        new AssetData('sections', Model::class, 'Sections', 'heroicon-o-document'),
        new AssetData('media', Model::class, 'Media', 'heroicon-o-document'),
    ]);
    CapellCore::shouldReceive('getAssets')->andReturn($assets);
    $record = new Site;
    $record->forceFill(['sections' => 1, 'pages' => 1, 'pages_count' => 1, 'sections_count' => 1, 'media_count' => 1]);

    $html = view('capell-admin::components.tables.columns.page-assets', [
        'getRecord' => fn (): Model => $record,
        'getLivewire' => fn (): null => null,
        'getExtraAttributes' => fn (): array => [],
        'attributes' => new ComponentAttributeBag,
    ])->render();

    if ($html === '') {
        $this->fail('Expected a rendered view.');
    }

    $document = new DOMDocument;
    $this->assertTrue(@$document->loadHTML($html));
    $buttons = $document->getElementsByTagName('button');
    expect($buttons->length)->toBe(3);
    foreach ($buttons as $button) {
        expect(trim($button->getAttribute('aria-label')))->not->toBe('');
    }
});

it('names the navigation tree controls in the rendered node', function (): void {
    $html = view('capell-admin::livewire.header.partials.navigation-tree-node', [
        'node' => ['id' => 1, 'site_id' => 1, 'name' => 'Example', 'has_children' => true, 'edit_url' => '/edit', 'public_url' => '/example'],
        'level' => 1,
        'expandedPages' => [],
        'renderChildren' => true,
        'searchMatchId' => null,
    ])->render();
    if ($html === '') {
        $this->fail('Expected a rendered view.');
    }

    $document = new DOMDocument;
    $this->assertTrue(@$document->loadHTML($html));
    $xpath = new DOMXPath($document);
    $controls = $xpath->query('//button | //a[@title]');
    $this->assertNotFalse($controls);
    expect($controls->length)->toBe(3);
    foreach ($controls as $control) {
        $this->assertInstanceOf(DOMElement::class, $control);
        expect(trim((string) $control->getAttribute('aria-label')))->not->toBe('');
    }
});
