<?php

declare(strict_types=1);

use Capell\Core\Actions\BuildCapellSiteFromSpecAction;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\PublishSiteSpecSiteAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Events\SiteSpecPublished;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Command\Command;

beforeEach(function (): void {
    CreateDefaultLanguagesAction::run(['en']);
    Queue::fake();
});

/** Authorises every publication except for the pages named. */
function denyPublishingOf(string ...$names): void
{
    app()->instance(AuthorizesPublicationTransition::class, new readonly class($names) implements AuthorizesPublicationTransition
    {
        /** @param list<string> $names */
        public function __construct(private array $names) {}

        public function allows(PublicationTransitionRequestData $request): bool
        {
            return ! in_array($request->record->getAttribute('name'), $this->names, true);
        }
    });
}

/** Allows the preflight check but refuses the real transition for the page named. */
function failTransitionOf(string $name): void
{
    app()->instance(AuthorizesPublicationTransition::class, new class($name) implements AuthorizesPublicationTransition
    {
        private int $calls = 0;

        public function __construct(private readonly string $name) {}

        public function allows(PublicationTransitionRequestData $request): bool
        {
            if ($request->record->getAttribute('name') !== $this->name) {
                return true;
            }

            return ++$this->calls < 2;
        }
    });
}

/** @return list<array<string, string>> */
function fivePages(): array
{
    return array_map(static fn (string $slug): array => [
        'slug' => $slug,
        'name' => ucfirst($slug),
        'title' => ucfirst($slug) . ' title',
        'content' => '<p>' . ucfirst($slug) . ' copy.</p>',
    ], ['home', 'about', 'services', 'team', 'contact']);
}

function privateFivePageSpec(): CapellSiteSpecData
{
    return CapellSiteSpecData::validateAndCreate([
        'site' => ['name' => 'Harbour Books'],
        'theme' => ['key' => 'default', 'colors' => ['primary' => '#123456']],
        'pages' => array_map(static fn (array $page, int $index): array => [
            'name' => $page['name'],
            'slug' => $page['slug'],
            'title' => $page['title'],
            'pageType' => 'default',
            'order' => $index,
            'sections' => [['type' => 'content', 'content' => $page['content']]],
        ], fivePages(), array_keys(fivePages())),
        'initialVisibility' => 'private',
        'acknowledgePublic' => false,
    ]);
}

function specPage(Site $site, string $slug): Page
{
    return $site->pages()->with('translations')->get()
        ->first(fn (Page $page): bool => ($page->translations->first()?->meta['slug'] ?? null) === $slug) ?? throw new RuntimeException('Missing page ' . $slug);
}

it('makes all five private pages public in one call and clears the caches once', function (): void {
    denyPublishingOf();
    $spec = privateFivePageSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $liveBefore = $site->pages()->get()->filter(fn (Page $page): bool => $page->publishVisibilityState() === PublishVisibilityStateEnum::published)->count();
    expect($liveBefore)->toBe(1)
        ->and(specPage($site, 'home')->translations->first()?->title)->toBe('Coming soon');

    Event::fake([SiteSpecPublished::class]);
    $published = PublishSiteSpecSiteAction::run($spec, $site, new User);

    expect($published)->toBe(4);
    Event::assertDispatchedTimes(SiteSpecPublished::class, 1);

    foreach (['home', 'about', 'services', 'team', 'contact'] as $slug) {
        $page = specPage($site->refresh(), $slug)->refresh();
        expect($page->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published)
            ->and($page->meta['noindex'] ?? null)->toBeFalse()
            ->and($page->meta)->not->toHaveKey('robots')
            ->and($page->translations->first()?->meta['robots'] ?? null)->toBe('index,follow');
    }

    $home = specPage($site, 'home')->translations->first();
    expect($home?->title)->toBe('Home title')
        ->and($home?->content)->toContain('Home copy.')
        ->and($home?->title)->not->toBe('Coming soon');
});

it('names the page that cannot be published and leaves every page as it was', function (): void {
    denyPublishingOf('Services');
    $spec = privateFivePageSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    Event::fake([SiteSpecPublished::class]);

    expect(fn (): int => PublishSiteSpecSiteAction::run($spec, $site, new User))
        ->toThrow(RuntimeException::class, 'Services');

    Event::assertNotDispatched(SiteSpecPublished::class);

    foreach (['about', 'services', 'team', 'contact'] as $slug) {
        expect(specPage($site->refresh(), $slug)->publishVisibilityState())->toBe(PublishVisibilityStateEnum::draft);
    }

    expect(specPage($site, 'home')->translations->first()?->title)->toBe('Coming soon');
});

it('refuses, naming the page, when the spec has a page the site does not have yet', function (): void {
    denyPublishingOf();
    $site = BuildCapellSiteFromSpecAction::run(privateFivePageSpec());
    specPage($site, 'team')->forceDelete();

    expect(fn (): int => PublishSiteSpecSiteAction::run(privateFivePageSpec(), $site->refresh(), new User))
        ->toThrow(RuntimeException::class, 'Team');
    expect(specPage($site, 'about')->publishVisibilityState())->toBe(PublishVisibilityStateEnum::draft);
});

it('keeps a homepage an editor already replaced', function (): void {
    denyPublishingOf();
    $spec = privateFivePageSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    specPage($site, 'home')->translations->first()?->update(['title' => 'Our own welcome', 'content' => '<p>Written by an editor.</p>']);

    PublishSiteSpecSiteAction::run($spec, $site, new User);

    $home = specPage($site->refresh(), 'home')->translations->first();
    expect($home?->title)->toBe('Our own welcome')
        ->and($home?->content)->toBe('<p>Written by an editor.</p>');
});

it('is safe to run again: nothing is left to publish the second time', function (): void {
    denyPublishingOf();
    $spec = privateFivePageSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);

    PublishSiteSpecSiteAction::run($spec, $site, new User);

    expect(PublishSiteSpecSiteAction::run($spec, $site->refresh(), new User))->toBe(0);
});

it('publishes from the command with --publish and --user, then reports how many pages went live', function (): void {
    denyPublishingOf();
    $spec = privateFivePageSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $user = config('auth.providers.users.model')::factory()->createOne();
    $path = tempnam(sys_get_temp_dir(), 'capell-site-spec-publish-');
    file_put_contents($path, json_encode($spec->toArray(), JSON_THROW_ON_ERROR));

    try {
        $command = $this->artisan('capell:site-spec-import', ['spec' => $path, '--site' => (string) $site->getKey(), '--publish' => true, '--user' => $user->email]);
        assert($command instanceof PendingCommand);
        $command->expectsOutputToContain('Published 4 page(s)')->assertExitCode(Command::SUCCESS)->run();

        expect(specPage($site->refresh(), 'contact')->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published);
    } finally {
        unlink($path);
    }
});

it('refuses --publish without a user, and with --plan, before changing anything', function (): void {
    denyPublishingOf();
    $spec = privateFivePageSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $path = tempnam(sys_get_temp_dir(), 'capell-site-spec-publish-');
    file_put_contents($path, json_encode($spec->toArray(), JSON_THROW_ON_ERROR));

    try {
        $noUser = $this->artisan('capell:site-spec-import', ['spec' => $path, '--site' => (string) $site->getKey(), '--publish' => true]);
        assert($noUser instanceof PendingCommand);
        $noUser->expectsOutputToContain('--user')->assertExitCode(Command::FAILURE)->run();

        $withPlan = $this->artisan('capell:site-spec-import', ['spec' => $path, '--site' => (string) $site->getKey(), '--plan' => true, '--publish' => true]);
        assert($withPlan instanceof PendingCommand);
        $withPlan->assertExitCode(Command::FAILURE)->run();

        expect(specPage($site->refresh(), 'about')->publishVisibilityState())->toBe(PublishVisibilityStateEnum::draft);
    } finally {
        unlink($path);
    }
});

it('undoes every earlier page when a later page fails after the writes began', function (): void {
    failTransitionOf('Team');
    $spec = privateFivePageSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);

    expect(fn (): int => PublishSiteSpecSiteAction::run($spec, $site, new User))
        ->toThrow(RuntimeException::class, 'Team');

    foreach (['about', 'services', 'team', 'contact'] as $slug) {
        $page = specPage($site->refresh(), $slug)->refresh();
        expect($page->publishVisibilityState())->toBe(PublishVisibilityStateEnum::draft)
            ->and($page->meta['noindex'] ?? null)->toBeTrue();
    }

    expect(specPage($site, 'home')->translations->first()?->title)->toBe('Coming soon');
});

it('keeps a noindex an editor chose on an existing page', function (): void {
    denyPublishingOf();
    $spec = privateFivePageSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $about = specPage($site, 'about');
    $about->forceFill(['meta' => array_merge($about->meta ?? [], ['noindex' => true, 'robots' => 'noindex,follow', 'editor' => 'kept'])])->save();

    PublishSiteSpecSiteAction::run($spec, $site, new User);

    $about = specPage($site->refresh(), 'about')->refresh();
    expect($about->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published)
        ->and(($about->meta['noindex'] ?? null))->toBeTrue()
        ->and(($about->meta['robots'] ?? null))->toBe('noindex,follow')
        ->and(($about->translations->first()?->meta['robots'] ?? null))->toBe('noindex,nofollow')
        ->and((specPage($site, 'services')->meta['noindex'] ?? null))->toBeFalse();
});

it('undoes the apply too when --publish cannot publish a page', function (): void {
    failTransitionOf('Contact');
    $spec = privateFivePageSpec();
    $site = BuildCapellSiteFromSpecAction::run($spec);
    $user = config('auth.providers.users.model')::factory()->createOne();
    $changed = $spec->toArray();
    $changed['pages'][1]['sections'] = [['type' => 'content', 'content' => '<p>Changed in the spec.</p>']];
    $path = tempnam(sys_get_temp_dir(), 'capell-site-spec-publish-');
    file_put_contents($path, json_encode($changed, JSON_THROW_ON_ERROR));

    try {
        $command = $this->artisan('capell:site-spec-import', ['spec' => $path, '--site' => (string) $site->getKey(), '--publish' => true, '--user' => $user->email]);
        assert($command instanceof PendingCommand);
        $command->expectsOutputToContain('Contact')->assertExitCode(Command::FAILURE)->run();

        expect(specPage($site->refresh(), 'about')->translations->first()?->content)->toContain('About copy.')
            ->and(specPage($site, 'about')->publishVisibilityState())->toBe(PublishVisibilityStateEnum::draft);
    } finally {
        unlink($path);
    }
});
