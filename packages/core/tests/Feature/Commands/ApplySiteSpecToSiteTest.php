<?php

declare(strict_types=1);

use Capell\Core\Actions\ApplySiteSpecToSiteAction;
use Capell\Core\Actions\BuildCapellSiteFromSpecAction;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\PlanSiteSpecAction;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Enums\SiteSpecPlanAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Core\Support\SiteSpec\SiteSpecHasher;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Command\Command;

beforeEach(function (): void {
    CreateDefaultLanguagesAction::run(['en']);
    Queue::fake();
});

/**
 * @param  list<array{slug: string, name: string, title: string, content: string}>  $pages
 * @return array<string, mixed>
 */
function applySpecPayload(array $pages, string $visibility = 'private'): array
{
    return [
        'site' => ['name' => 'Harbour Books'],
        'theme' => ['key' => 'default', 'colors' => ['primary' => '#123456']],
        'pages' => array_map(fn (array $page, int $index): array => [
            'name' => $page['name'],
            'slug' => $page['slug'],
            'title' => $page['title'],
            'pageType' => 'default',
            'order' => $index,
            'sections' => [['type' => 'content', 'content' => $page['content']]],
        ], $pages, array_keys($pages)),
        'initialVisibility' => $visibility,
        'acknowledgePublic' => $visibility === 'public',
    ];
}

function applySpec(array $payload): CapellSiteSpecData
{
    return CapellSiteSpecData::validateAndCreate($payload);
}

function applyPage(Site $site, string $slug): Page
{
    return $site->pages()->with('translations')->get()
        ->first(fn (Page $page): bool => ($page->translations->first()?->meta['slug'] ?? null) === $slug) ?? throw new RuntimeException('Missing page ' . $slug);
}

/** @return list<array<string, string>> */
function basePages(): array
{
    return [
        ['slug' => 'home', 'name' => 'Home', 'title' => 'Welcome', 'content' => '<p>Home copy.</p>'],
        ['slug' => 'about', 'name' => 'About', 'title' => 'About us', 'content' => '<p>About copy.</p>'],
    ];
}

it('plans every page as a create when there is no site yet, writing nothing', function (): void {
    $siteCount = Site::query()->count();

    $plan = PlanSiteSpecAction::run(applySpec(applySpecPayload(basePages())));

    expect($plan->count(SiteSpecPlanAction::Create))->toBe(2)
        ->and($plan->hasChanges())->toBeTrue()
        ->and(Site::query()->count())->toBe($siteCount);
});

it('plans creates, updates and skips against an existing site and writes nothing', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    $about = applyPage($site, 'about');
    $about->translations->first()?->update(['content' => '<p>Edited.</p>']);

    $changed = basePages();
    $changed[] = ['slug' => 'contact', 'name' => 'Contact', 'title' => 'Contact us', 'content' => '<p>Write to us.</p>'];
    $pageCount = $site->pages()->count();

    $plan = PlanSiteSpecAction::run(applySpec(applySpecPayload($changed)), $site->refresh());
    $byKey = collect($plan->items)->where('kind', 'page')->keyBy('key');

    expect($byKey['contact']->action)->toBe(SiteSpecPlanAction::Create)
        ->and($byKey['about']->action)->toBe(SiteSpecPlanAction::Update)
        ->and($byKey['about']->changes)->toBe(['content'])
        ->and($site->pages()->count())->toBe($pageCount)
        ->and(applyPage($site, 'about')->translations->first()?->content)->toBe('<p>Edited.</p>');
});

it('applies a changed spec to the existing site instead of creating another one', function (): void {
    $draft = basePages();
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload($draft)));
    // Pages a private import creates after the homepage start as drafts.
    $siteCount = Site::query()->count();

    $changed = basePages();
    $changed[1]['title'] = 'About the shop';
    $changed[1]['content'] = '<p>Updated about copy.</p>';
    $changed[] = ['slug' => 'contact', 'name' => 'Contact', 'title' => 'Contact us', 'content' => '<p>Write to us.</p>'];
    $spec = applySpec(applySpecPayload($changed));

    $plan = ApplySiteSpecToSiteAction::run($spec, $site->refresh());
    $site->refresh();

    expect(Site::query()->count())->toBe($siteCount)
        ->and($plan->count(SiteSpecPlanAction::Create))->toBe(1)
        ->and(applyPage($site, 'about')->translations->first()?->title)->toBe('About the shop')
        ->and(applyPage($site, 'about')->translations->first()?->content)->toContain('Updated about copy.')
        ->and(applyPage($site, 'contact')->name)->toBe('Contact')
        ->and($site->meta['site_spec_hash'])->toBe(SiteSpecHasher::hash($spec));
});

it('applies the same spec twice without changing anything the second time', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    $changed = basePages();
    $changed[1]['content'] = '<p>Second version.</p>';
    $spec = applySpec(applySpecPayload($changed));

    ApplySiteSpecToSiteAction::run($spec, $site->refresh());
    $second = ApplySiteSpecToSiteAction::run($spec, $site->refresh());

    expect($second->hasChanges())->toBeFalse()
        ->and($site->pages()->count())->toBe(2);
});

it('never lets a private spec change a page that is already live', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    // The homepage of a private import is published with a coming-soon placeholder.
    $home = applyPage($site, 'home');
    $placeholder = $home->translations->first()?->content;

    $plan = ApplySiteSpecToSiteAction::run(applySpec(applySpecPayload(basePages())), $site->refresh());
    $homeItem = collect($plan->items)->firstWhere('key', 'home');

    expect($homeItem?->action)->toBe(SiteSpecPlanAction::Skip)
        ->and($homeItem?->note)->toBe('live_page_held')
        ->and(applyPage($site, 'home')->translations->first()?->content)->toBe($placeholder)
        ->and(PublishSentinel::isDraftValue(applyPage($site, 'home')->visible_from))->toBeFalse();
});

it('creates pages a private spec adds as drafts', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    $changed = basePages();
    $changed[] = ['slug' => 'contact', 'name' => 'Contact', 'title' => 'Contact us', 'content' => '<p>Write to us.</p>'];

    ApplySiteSpecToSiteAction::run(applySpec(applySpecPayload($changed)), $site->refresh());

    expect(PublishSentinel::isDraftValue(applyPage($site->refresh(), 'contact')->visible_from))->toBeTrue();
});

it('updates a live page when the spec is public and acknowledged', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages(), 'public')));
    $changed = basePages();
    $changed[0]['content'] = '<p>New home copy.</p>';

    ApplySiteSpecToSiteAction::run(applySpec(applySpecPayload($changed, 'public')), $site->refresh());

    expect(applyPage($site->refresh(), 'home')->translations->first()?->content)->toContain('New home copy.');
});

it('refuses a spec written in a language the site does not have', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    CreateDefaultLanguagesAction::run(['fr']);
    $payload = applySpecPayload(basePages());
    $payload['language'] = ['code' => 'fr', 'name' => 'French', 'locale' => 'fr_FR', 'flag' => 'fr', 'default' => false];

    expect(fn (): mixed => ApplySiteSpecToSiteAction::run(applySpec($payload), $site->refresh()))->toThrow(RuntimeException::class, 'does not have the language');
});

it('prints the plan from the import command with --plan and writes nothing', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    $changed = basePages();
    $changed[1]['content'] = '<p>Plan only.</p>';
    $changed[] = ['slug' => 'contact', 'name' => 'Contact', 'title' => 'Contact us', 'content' => '<p>Write to us.</p>'];
    $path = tempnam(sys_get_temp_dir(), 'capell-site-spec-plan-');
    file_put_contents($path, json_encode(applySpecPayload($changed), JSON_THROW_ON_ERROR));
    $pageCount = $site->pages()->count();

    try {
        $command = $this->artisan('capell:site-spec-import', ['spec' => $path, '--plan' => true, '--site' => (string) $site->getKey()]);
        assert($command instanceof PendingCommand);
        $command->expectsOutputToContain('Nothing was written')->assertExitCode(Command::SUCCESS)->run();

        expect($site->pages()->count())->toBe($pageCount);
    } finally {
        unlink($path);
    }
});

it('applies a changed spec to a named site from the import command', function (): void {
    $site = BuildCapellSiteFromSpecAction::run(applySpec(applySpecPayload(basePages())));
    $changed = basePages();
    $changed[] = ['slug' => 'contact', 'name' => 'Contact', 'title' => 'Contact us', 'content' => '<p>Write to us.</p>'];
    $path = tempnam(sys_get_temp_dir(), 'capell-site-spec-apply-');
    file_put_contents($path, json_encode(applySpecPayload($changed), JSON_THROW_ON_ERROR));

    try {
        $command = $this->artisan('capell:site-spec-import', ['spec' => $path, '--site' => (string) $site->getKey()]);
        assert($command instanceof PendingCommand);
        $command->expectsOutputToContain('1 page(s) created')->assertExitCode(Command::SUCCESS)->run();

        expect($site->pages()->count())->toBe(3);
    } finally {
        unlink($path);
    }
});

it('reports an unknown site and refuses mixing the plan options with repair', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-site-spec-bad-');
    file_put_contents($path, json_encode(applySpecPayload(basePages()), JSON_THROW_ON_ERROR));

    try {
        $unknown = $this->artisan('capell:site-spec-import', ['spec' => $path, '--site' => '999999']);
        assert($unknown instanceof PendingCommand);
        $unknown->expectsOutputToContain('No site with the ID 999999')->assertExitCode(Command::FAILURE)->run();

        $mixed = $this->artisan('capell:site-spec-import', ['spec' => $path, '--plan' => true, '--repair-private' => true]);
        assert($mixed instanceof PendingCommand);
        $mixed->expectsOutputToContain('on their own')->assertExitCode(Command::FAILURE)->run();
    } finally {
        unlink($path);
    }
});
