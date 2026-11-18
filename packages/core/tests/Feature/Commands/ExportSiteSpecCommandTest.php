<?php

declare(strict_types=1);

use Capell\Core\Actions\BuildCapellSiteFromSpecAction;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\ExportSiteSpecAction;
use Capell\Core\Actions\PlanSiteSpecAction;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\SiteSpecPlanItemData;
use Capell\Core\Enums\SiteSpecPlanAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Publishing\PublishSentinel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Command\Command;

beforeEach(function (): void {
    CreateDefaultLanguagesAction::run(['en']);
    Queue::fake();
    $this->exportDirectory = sys_get_temp_dir() . '/capell-site-spec-export-' . bin2hex(random_bytes(6));
    mkdir($this->exportDirectory);
});

afterEach(function (): void {
    foreach (glob($this->exportDirectory . '/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->exportDirectory);
});

/**
 * A public site built from a spec, with its pages written in reverse slug order so
 * an export that followed database order would not come out sorted.
 */
function exportedSite(): Site
{
    $pages = [
        ['slug' => 'home', 'name' => 'Home', 'title' => 'Welcome', 'content' => '<p>Home copy.</p>'],
        ['slug' => 'services', 'name' => 'Services', 'title' => 'What we do', 'content' => '<p>Repairs.</p><p>Servicing.</p>'],
        ['slug' => 'about', 'name' => 'About', 'title' => 'About us', 'content' => '<p>Family run since 1990.</p>'],
    ];

    return BuildCapellSiteFromSpecAction::run(CapellSiteSpecData::validateAndCreate([
        'site' => ['name' => 'Harbour Garage', 'businessName' => 'Harbour Garage Ltd'],
        'theme' => ['key' => 'default', 'colors' => ['primary' => '#123456']],
        'pages' => array_map(static fn (array $page, int $index): array => [
            'name' => $page['name'],
            'slug' => $page['slug'],
            'title' => $page['title'],
            'pageType' => 'default',
            'order' => $index,
            'sections' => [['type' => 'content', 'content' => $page['content']]],
        ], $pages, array_keys($pages)),
        'initialVisibility' => 'public',
        'acknowledgePublic' => true,
    ]));
}

function runExport(Site $site, string $path, bool $public = true): void
{
    expect(Artisan::call('capell:site-spec-export', ['site' => (string) $site->getKey(), '--output' => $path, '--public' => $public]))->toBe(Command::SUCCESS);
}

/** @return array<string, mixed> */
function exportedPayload(string $path): array
{
    $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($payload));

    return $payload;
}

/** @return array<string, string|null> */
function siteSnapshot(Site $site): array
{
    return [
        'pages' => (string) $site->pages()->count(),
        'translations' => (string) Translation::query()->count(),
        'titles' => $site->pages()->with('translations')->get()->sortBy('id')->map(
            static fn (Page $page): string => $page->name . '|' . $page->translations->first()?->title . '|' . $page->translations->first()?->content,
        )->implode("\n"),
        'applied' => $site->refresh()->meta['site_spec_applied_hash'] ?? null,
    ];
}

it('exports the same site to byte-identical JSON, home first and then by slug', function (): void {
    $site = exportedSite();

    runExport($site, $this->exportDirectory . '/first.json');
    runExport($site, $this->exportDirectory . '/second.json');

    $first = (string) file_get_contents($this->exportDirectory . '/first.json');
    $payload = exportedPayload($this->exportDirectory . '/first.json');

    expect($first)->toBe((string) file_get_contents($this->exportDirectory . '/second.json'))
        ->and($first)->toEndWith("}\n")
        ->and(array_column($payload['pages'], 'slug'))->toBe(['home', 'about', 'services'])
        ->and(array_column($payload['pages'], 'order'))->toBe([0, 1, 2])
        ->and(array_keys($payload))->toBe(array_values(Arr::sort(array_keys($payload))))
        ->and($payload['site'])->toBe(['businessName' => null, 'description' => null, 'name' => 'Harbour Garage', 'organisationType' => null])
        ->and($payload['theme']['colors']['primary'])->toBe('#123456')
        ->and($payload['pages'][2]['sections'][0]['content'])->toBe('<p>Repairs.</p><p>Servicing.</p>');
});

it('plans no change when an export is applied back to the site it came from', function (): void {
    $site = exportedSite();
    $export = ExportSiteSpecAction::run($site, true);

    $plan = PlanSiteSpecAction::run($export->spec, $site);

    expect($plan->hasChanges())->toBeFalse()
        ->and($plan->count(SiteSpecPlanAction::Skip))->toBe(count($export->spec->pages) + 1)
        ->and(array_filter($plan->items, static fn (SiteSpecPlanItemData $item): bool => $item->differences !== []))->toBe([]);
});

it('shows a changed export line by line with --diff and writes nothing', function (): void {
    $site = exportedSite();
    $path = $this->exportDirectory . '/site.json';
    runExport($site, $path);
    $payload = exportedPayload($path);
    $payload['pages'][2]['sections'][0]['content'] = '<p>Repairs.</p><p>MOT testing.</p>';
    $payload['pages'][1]['title'] = 'Our story';
    $payload['pages'][] = ['name' => 'Contact', 'slug' => 'contact', 'title' => 'Contact us', 'pageType' => 'default', 'sections' => [['type' => 'content', 'content' => '<p>Call us.</p>']]];
    file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
    $before = siteSnapshot($site);

    $command = $this->artisan('capell:site-spec-import', ['spec' => $path, '--diff' => true, '--site' => (string) $site->getKey()]);
    assert($command instanceof PendingCommand);
    $command
        ->expectsOutputToContain('page "about": update')
        ->expectsOutputToContain('- About us')
        ->expectsOutputToContain('+ Our story')
        ->expectsOutputToContain('page "services": update')
        ->expectsOutputToContain('  <p>Repairs.</p>')
        ->expectsOutputToContain('- <p>Servicing.</p>')
        ->expectsOutputToContain('+ <p>MOT testing.</p>')
        ->expectsOutputToContain('page "contact": create')
        ->expectsOutputToContain('+ <p>Call us.</p>')
        ->doesntExpectOutputToContain('page "home"')
        ->expectsOutputToContain('Plan: 1 to create, 2 to update, 2 unchanged. Nothing was written.')
        ->assertExitCode(Command::SUCCESS)
        ->run();

    expect(siteSnapshot($site))->toBe($before);
});

it('plans exactly the creates, updates and skips of a changed export, and --plan writes nothing', function (): void {
    $site = exportedSite();
    $path = $this->exportDirectory . '/site.json';
    runExport($site, $path);
    $payload = exportedPayload($path);
    $payload['pages'][1]['name'] = 'About the garage';
    $payload['pages'][] = ['name' => 'Contact', 'slug' => 'contact', 'title' => 'Contact us', 'pageType' => 'default', 'sections' => []];
    $before = siteSnapshot($site);

    $plan = PlanSiteSpecAction::run(CapellSiteSpecData::validateAndCreate($payload), $site);
    $actions = [];

    foreach ($plan->items as $item) {
        $actions[$item->kind . ':' . $item->key] = [$item->action, $item->changes];
    }

    expect($actions)->toBe([
        'page:home' => [SiteSpecPlanAction::Skip, []],
        'page:about' => [SiteSpecPlanAction::Update, ['name']],
        'page:services' => [SiteSpecPlanAction::Skip, []],
        'page:contact' => [SiteSpecPlanAction::Create, []],
        'theme:default' => [SiteSpecPlanAction::Skip, []],
    ]);

    file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
    $command = $this->artisan('capell:site-spec-import', ['spec' => $path, '--plan' => true, '--site' => (string) $site->getKey()]);
    assert($command instanceof PendingCommand);
    $command->expectsOutputToContain('Nothing was written')->assertExitCode(Command::SUCCESS)->run();

    expect(siteSnapshot($site))->toBe($before);
});

it('promotes an edited export to the site and exports the edit back unchanged', function (): void {
    $site = exportedSite();
    $path = $this->exportDirectory . '/site.json';
    runExport($site, $path);
    $payload = exportedPayload($path);
    $payload['pages'][2]['title'] = 'Repairs and servicing';
    file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
    $untouched = applyExportedPage($site, 'about')->translations->first()?->updated_at;
    $this->travel(5)->minutes();

    $command = $this->artisan('capell:site-spec-import', ['spec' => $path, '--site' => (string) $site->getKey()]);
    assert($command instanceof PendingCommand);
    $command->expectsOutputToContain('Created 0, updated 1')->assertExitCode(Command::SUCCESS)->run();

    runExport($site, $this->exportDirectory . '/after.json');

    expect(exportedPayload($this->exportDirectory . '/after.json'))->toBe($payload)
        ->and(applyExportedPage($site, 'about')->translations->first()?->updated_at?->toIso8601String())->toBe($untouched?->toIso8601String());
});

it('marks an export private unless --public is given', function (): void {
    $site = exportedSite();

    runExport($site, $this->exportDirectory . '/private.json', false);
    runExport($site, $this->exportDirectory . '/public.json');

    expect(exportedPayload($this->exportDirectory . '/private.json'))->toMatchArray(['initialVisibility' => 'private', 'acknowledgePublic' => false])
        ->and(exportedPayload($this->exportDirectory . '/public.json'))->toMatchArray(['initialVisibility' => 'public', 'acknowledgePublic' => true]);
});

it('reports an unknown site and a folder it cannot write to', function (): void {
    $missing = $this->artisan('capell:site-spec-export', ['site' => '999999']);
    assert($missing instanceof PendingCommand);
    $missing->expectsOutputToContain('No site with the ID 999999 was found')->assertExitCode(Command::FAILURE)->run();

    $site = exportedSite();
    $unwritable = $this->artisan('capell:site-spec-export', ['site' => (string) $site->getKey(), '--output' => $this->exportDirectory . '/missing/site.json']);
    assert($unwritable instanceof PendingCommand);
    $unwritable->expectsOutputToContain('Could not write the SiteSpec')->assertExitCode(Command::FAILURE)->run();
});

function applyExportedPage(Site $site, string $slug): Page
{
    return $site->pages()->with('translations')->get()
        ->first(static fn (Page $page): bool => ($page->translations->first()?->meta['slug'] ?? null) === $slug) ?? throw new RuntimeException('Missing page ' . $slug);
}

it('exports editor HTML as an import would store it, and writes a missing title once', function (): void {
    $site = exportedSite();
    $about = applyExportedPage($site, 'about');
    $about->translations->first()?->update(['content' => '<p onclick="alert(1)">Family run.</p><script>alert(2)</script>', 'title' => null]);

    $export = ExportSiteSpecAction::run($site, true);
    $plan = PlanSiteSpecAction::run($export->spec, $site);
    $aboutSpec = collect($export->spec->pages)->firstWhere('slug', 'about');

    $changed = array_values(array_filter($plan->items, static fn (SiteSpecPlanItemData $item): bool => $item->action !== SiteSpecPlanAction::Skip));

    expect($changed)->toHaveCount(1)
        ->and([$changed[0]->key, $changed[0]->action, $changed[0]->changes])->toBe(['about', SiteSpecPlanAction::Update, ['title']])
        ->and($aboutSpec?->title)->toBe('About')
        ->and($aboutSpec?->sections[0]->content)->toContain('Family run.')
        ->and($aboutSpec?->sections[0]->content)->not->toContain('onclick')
        ->and($aboutSpec?->sections[0]->content)->not->toContain('<script');
});

it('exports only hex theme colours and leaves out a placeholder home page, naming it', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(CapellSiteSpecData::validateAndCreate([
        'site' => ['name' => 'Quiet Studio'],
        'theme' => ['key' => 'default', 'colors' => ['primary' => '#123456']],
        'pages' => [
            ['name' => 'Home', 'slug' => 'home', 'title' => 'Welcome', 'pageType' => 'default', 'sections' => [['type' => 'content', 'content' => '<p>Hello.</p>']]],
            ['name' => 'Work', 'slug' => 'work', 'title' => 'Our work', 'pageType' => 'default', 'sections' => [['type' => 'content', 'content' => '<p>Projects.</p>']]],
        ],
    ]));
    $theme = $site->theme;
    $theme->forceFill(['meta' => array_merge($theme->meta ?? [], ['link_color' => 'rgb(1, 2, 3)'])])->save();
    $path = $this->exportDirectory . '/private.json';

    $command = $this->artisan('capell:site-spec-export', ['site' => (string) $site->getKey(), '--output' => $path]);
    assert($command instanceof PendingCommand);
    $command->expectsOutputToContain('still the placeholder home page: Home')->assertExitCode(Command::SUCCESS)->run();
    $payload = exportedPayload($path);

    expect(array_column($payload['pages'], 'slug'))->toBe(['work'])
        ->and($payload['theme']['linkColor'])->toBeNull()
        ->and($payload['theme']['colors']['primary'])->toBe('#123456');
});

it('leaves pages that are not live out of a public export, so promoting it cannot publish them', function (): void {
    $site = exportedSite();
    applyExportedPage($site, 'services')->update(['visible_from' => PublishSentinel::draftValue()]);
    $path = $this->exportDirectory . '/public.json';

    $command = $this->artisan('capell:site-spec-export', ['site' => (string) $site->getKey(), '--output' => $path, '--public' => true]);
    assert($command instanceof PendingCommand);
    $command->expectsOutputToContain('not live, or only some visitors can see them: Services')->assertExitCode(Command::SUCCESS)->run();
    runExport($site, $this->exportDirectory . '/private.json', false);

    expect(array_column(exportedPayload($path)['pages'], 'slug'))->toBe(['home', 'about'])
        ->and(array_column(exportedPayload($this->exportDirectory . '/private.json')['pages'], 'slug'))->toBe(['home', 'about', 'services']);
});
