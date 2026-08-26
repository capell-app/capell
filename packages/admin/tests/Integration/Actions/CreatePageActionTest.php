<?php

declare(strict_types=1);

use Capell\Admin\Actions\CreatePageAction as AdminCreatePageAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\assertDatabaseMissing;

it('creates a page with validated payload', function (): void {
    $site = Site::factory()->createOne();

    $payload = [
        'site_id' => $site->id,
        'name' => 'New Page',
        'blueprint_id' => Blueprint::factory()->page()->create()->id,
        'layout_id' => Layout::factory()->createOne()->id,
    ];

    /** @var Page $page */
    $page = AdminCreatePageAction::run($payload);

    expect($page)->toBeInstanceOf(Page::class)
        ->and($page->name)->toBe('New Page');
});

it('rolls back the page when creating its translation fails', function (): void {
    $site = Site::factory()->createOne();
    $language = $site->language;
    $blueprint = Blueprint::factory()->page()->create();
    $layout = Layout::factory()->createOne();

    Event::listen('eloquent.creating: ' . Translation::class, function (): void {
        throw new RuntimeException('Translation creation failed.');
    });

    expect(fn (): Page => AdminCreatePageAction::run([
        'site_id' => $site->id,
        'name' => 'Rolled Back Page',
        'blueprint_id' => $blueprint->id,
        'layout_id' => $layout->id,
        'translations' => [[
            'language_id' => $language->id,
            'title' => 'Rolled Back Page',
            'content' => [],
        ]],
    ]))->toThrow(RuntimeException::class, 'Translation creation failed.');

    assertDatabaseMissing(Page::class, ['name' => 'Rolled Back Page']);
});
