<?php

declare(strict_types=1);

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Frontend\Actions\GetPageVariablesAction;
use Capell\Frontend\Contracts\FrontendContextReader;

it('builds page variables from the active frontend context when route params are unavailable', function (): void {
    $page = Page::factory()->make();
    $page->setRelation('translation', Translation::factory()->make([
        'title' => 'Context Page',
        'meta' => ['label' => '<strong>Context Label</strong>'],
    ]));

    $site = Site::factory()->make(['name' => 'Fallback Site']);
    $site->setRelation('translation', Translation::factory()->make([
        'title' => 'Context Site',
    ]));

    app()->instance(FrontendContextReader::class, new readonly class($page, $site) implements FrontendContextReader
    {
        public function __construct(
            private Pageable $page,
            private Site $site,
        ) {}

        public function site(): Site
        {
            return $this->site;
        }

        public function language(): ?Language
        {
            return null;
        }

        public function page(): Pageable
        {
            return $this->page;
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): ?Theme
        {
            return null;
        }

        public function params(): array
        {
            throw new RuntimeException('Route parameters are unavailable before frontend boot.');
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return false;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return null;
        }
    });

    $variables = GetPageVariablesAction::run();

    expect($variables['site'])->toBe('Context Site')
        ->and($variables['title'])->toBe('Context Page')
        ->and($variables['label'])->toBe('Context Label')
        ->and($variables['page']['translation'])->toBe([
            'title' => 'Context Page',
            'label' => 'Context Label',
        ])
        ->and($variables)->not->toHaveKey('archive_month');
});

it('adds archive date variables and parent labels from public route params', function (): void {
    $language = Language::factory()->english()->create();
    $site = Site::factory()->recycle($language)->withTranslations($language, ['title' => 'Journal'])->create();
    $type = Blueprint::factory()->page()->create();
    $parent = Page::factory()
        ->site($site)
        ->type($type)
        ->withTranslations($language, ['title' => 'Article'], '/articles')
        ->create();
    $page = Page::factory()
        ->site($site)
        ->type($type)
        ->parent($parent)
        ->withTranslations($language, [
            'title' => 'February Notes',
            'meta' => ['label' => '<em>Note</em>'],
        ], '/articles/february-notes')
        ->create();
    $page->load(['translation', 'parent.translation']);

    $site->load('translation');

    app()->instance(FrontendContextReader::class, new readonly class implements FrontendContextReader
    {
        public function site(): ?Site
        {
            return null;
        }

        public function language(): ?Language
        {
            return null;
        }

        public function page(): ?Pageable
        {
            return null;
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): ?Theme
        {
            return null;
        }

        public function params(): array
        {
            return [
                'date' => '2025-02',
                'category' => 'news',
            ];
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return false;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return null;
        }
    });

    $variables = GetPageVariablesAction::run($page, $site, [
        'title' => ['en' => 'Manual Override', 'fr' => 'Remplacement manuel'],
    ]);

    expect($variables['site'])->toBe('Journal')
        ->and($variables['title'])->toBe('Manual Override')
        ->and($variables['page']['title'])->toBe('February Notes')
        ->and($variables['label'])->toBe('Note')
        ->and($variables['parent'])->toBe('Articles')
        ->and($variables['page']['parent'])->toBe('Articles')
        ->and($variables['category'])->toBe('news')
        ->and($variables['archive_month'])->toBe('February')
        ->and($variables['archive_year'])->toBe(2025)
        ->and($variables['archive_date']->toDateString())->toBe('2025-02-01');
});
