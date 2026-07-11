<?php

declare(strict_types=1);

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ContentListingSectionData;
use Capell\Core\ThemeStudio\Data\CtaSectionData;
use Capell\Core\ThemeStudio\Data\FeatureSectionData;
use Capell\Core\ThemeStudio\Data\FooterData;
use Capell\Core\ThemeStudio\Data\GenericSectionData;
use Capell\Core\ThemeStudio\Data\HeroSectionData;
use Capell\Core\ThemeStudio\Data\NavigationData;
use Capell\Core\ThemeStudio\Data\ProofSectionData;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Support\CapellFrontendContext;
use Capell\Frontend\ThemeStudio\Adapters\CapellFrontendThemePageAdapter;

it('builds theme page sections from page theme render data', function (): void {
    $site = Site::factory()->make(['name' => 'Capell Demo']);
    $translation = Translation::factory()->make([
        'title' => 'Translated launch page',
        'content' => '<p>Translated content summary that should only be used as fallback copy.</p>',
    ]);
    $page = Page::factory()->make([
        'name' => 'Launch page',
        'meta' => [
            'theme' => [
                'render_data' => [
                    'hero' => [
                        'heading' => 'Launch faster',
                        'eyebrow' => 'Platform',
                        'summary' => 'Hero summary',
                        'actions' => [['label' => 'Start', 'url' => '/start']],
                    ],
                    'features_heading' => 'Core modules',
                    'features' => [
                        ['name' => 'Pages', 'summary' => 'Structured content', 'icon' => 'file-text'],
                    ],
                    'spotlight' => [
                        'heading' => 'Spotlight entries',
                        'items' => [['title' => 'Case study', 'url' => '/case-study']],
                    ],
                    'gallery' => [
                        ['title' => 'Gallery image', 'imageUrl' => '/gallery.jpg'],
                    ],
                    'items' => [
                        'heading' => 'Latest content',
                        'variant' => 'cards',
                        'items' => [['title' => 'Article', 'summary' => 'Article summary']],
                    ],
                    'proof' => [
                        'heading' => 'Customer proof',
                        'items' => [['quote' => 'Reliable CMS', 'name' => 'Editor']],
                    ],
                    'cta' => [
                        'heading' => 'Ready to publish?',
                        'actions' => [['label' => 'Book demo', 'url' => '/demo']],
                    ],
                    'navigation' => [
                        'brandName' => 'Demo brand',
                        'items' => [['label' => 'Work', 'url' => '#work']],
                        'ctaLabel' => 'Contact',
                        'ctaUrl' => '/contact',
                    ],
                    'footer' => [
                        'brandName' => 'Footer brand',
                        'summary' => 'Footer summary',
                        'columns' => [
                            ['heading' => 'Explore', 'links' => [['label' => 'Home', 'url' => '/']]],
                        ],
                    ],
                ],
            ],
        ],
    ]);
    $page->setRelation('translation', $translation);

    bindThemeAdapterContext($page, $site);

    $themePage = (new CapellFrontendThemePageAdapter)->currentPage();

    expect($themePage->title)->toBe('Translated launch page')
        ->and($themePage->brand->primaryColor)->toBe('#123456')
        ->and($themePage->navigation)->toBeInstanceOf(NavigationData::class)
        ->and($themePage->navigation?->brandName)->toBe('Demo brand')
        ->and($themePage->footer)->toBeInstanceOf(FooterData::class)
        ->and($themePage->footer?->brandName)->toBe('Footer brand')
        ->and(array_map(fn (object $section): string => $section->key(), $themePage->sections))->toBe([
            'hero',
            'features',
            'content-listing',
            'content-listing',
            'content-listing',
            'proof',
            'cta',
        ])
        ->and($themePage->sections[0])->toBeInstanceOf(HeroSectionData::class)
        ->and($themePage->sections[1])->toBeInstanceOf(FeatureSectionData::class)
        ->and($themePage->sections[2])->toBeInstanceOf(ContentListingSectionData::class)
        ->and($themePage->sections[3])->toBeInstanceOf(ContentListingSectionData::class)
        ->and($themePage->sections[4])->toBeInstanceOf(ContentListingSectionData::class)
        ->and($themePage->sections[5])->toBeInstanceOf(ProofSectionData::class)
        ->and($themePage->sections[6])->toBeInstanceOf(CtaSectionData::class);
});

it('builds navigation and footer from translation render data lists', function (): void {
    $site = Site::factory()->make(['name' => 'Fallback Site']);
    $translation = Translation::factory()->make([
        'title' => '',
        'content' => '<p>Translation body</p>',
        'meta' => [
            'render_data' => [
                'navigation' => [
                    ['label' => 'Overview', 'url' => '#overview'],
                ],
                'footer' => [
                    ['heading' => 'Footer links', 'links' => [['label' => 'Contact', 'url' => '/contact']]],
                ],
                'proof' => [
                    ['quote' => 'Stable output', 'name' => 'Publisher'],
                ],
            ],
        ],
    ]);
    $page = Page::factory()->make(['name' => 'Fallback page', 'meta' => []]);
    $page->setRelation('translation', $translation);

    bindThemeAdapterContext($page, $site);

    $themePage = (new CapellFrontendThemePageAdapter)->currentPage();

    expect($themePage->title)->toBe('Fallback page')
        ->and($themePage->navigation?->brandName)->toBe('Fallback Site')
        ->and($themePage->navigation?->items)->toBe([['label' => 'Overview', 'url' => '#overview']])
        ->and($themePage->footer?->brandName)->toBe('Fallback Site')
        ->and($themePage->footer?->columns[0]['heading'])->toBe('Footer links')
        ->and($themePage->sections)->toHaveCount(1)
        ->and($themePage->sections[0])->toBeInstanceOf(ProofSectionData::class);
});

it('prefers localized render data over page render data', function (): void {
    $site = Site::factory()->make(['name' => 'Localized Site']);
    $translation = Translation::factory()->make([
        'title' => 'French page',
        'content' => '<p>French content.</p>',
        'meta' => [
            'theme' => [
                'render_data' => [
                    'hero' => [
                        'heading' => 'Bonjour',
                        'actions' => [['label' => 'Lire', 'url' => '/fr/lire']],
                    ],
                ],
            ],
        ],
    ]);
    $page = Page::factory()->make([
        'name' => 'English page',
        'meta' => [
            'theme' => [
                'render_data' => [
                    'hero' => [
                        'heading' => 'Hello',
                        'actions' => [['label' => 'Read', 'url' => '/read']],
                    ],
                ],
            ],
        ],
    ]);
    $page->setRelation('translation', $translation);

    bindThemeAdapterContext($page, $site);

    $themePage = (new CapellFrontendThemePageAdapter)->currentPage();
    $heroSection = expectPresent($themePage->sections[0] ?? null);
    assert($heroSection instanceof HeroSectionData);

    expect($heroSection->heading)->toBe('Bonjour')
        ->and($heroSection->actions)->toBe([['label' => 'Lire', 'url' => '/fr/lire']]);
});

it('keeps legacy theme demo metadata as a compatibility render data source', function (): void {
    $site = Site::factory()->make(['name' => 'Legacy Site']);
    $translation = Translation::factory()->make([
        'title' => 'Legacy page',
        'content' => '<p>Legacy content</p>',
        'meta' => [
            'theme_demo' => [
                'hero' => [
                    'heading' => 'Legacy hero',
                    'summary' => 'Legacy summary',
                ],
            ],
        ],
    ]);
    $page = Page::factory()->make(['name' => 'Legacy fallback', 'meta' => []]);
    $page->setRelation('translation', $translation);

    bindThemeAdapterContext($page, $site);

    $themePage = (new CapellFrontendThemePageAdapter)->currentPage();
    $heroSection = expectPresent($themePage->sections[0] ?? null);
    assert($heroSection instanceof HeroSectionData);

    expect($heroSection->heading)->toBe('Legacy hero')
        ->and($heroSection->summary)->toBe('Legacy summary');
});

it('sanitizes public urls from theme render data before building sections', function (): void {
    $site = Site::factory()->make(['name' => 'Security Site']);
    $translation = Translation::factory()->make([
        'title' => 'Security page',
        'content' => '<p>Public content.</p>',
    ]);
    $page = Page::factory()->make([
        'name' => 'Security page',
        'meta' => [
            'theme' => [
                'render_data' => [
                    'hero' => [
                        'heading' => 'Safe hero',
                        'actions' => [
                            ['label' => 'Unsafe action', 'url' => 'javascript:alert(1)'],
                            ['label' => 'Email', 'url' => 'mailto:editor@example.com'],
                        ],
                        'mediaUrl' => 'data:text/html,<script>alert(1)</script>',
                    ],
                    'items' => [
                        [
                            'title' => 'Unsafe entry',
                            'url' => 'javascript:alert(2)',
                            'imageUrl' => 'data:image/svg+xml,<svg onload=alert(3)>',
                        ],
                    ],
                    'navigation' => [
                        'brandName' => 'Security brand',
                        'items' => [['label' => 'Unsafe nav', 'url' => 'javascript:alert(4)']],
                        'ctaLabel' => 'Unsafe CTA',
                        'ctaUrl' => 'javascript:alert(5)',
                    ],
                    'footer' => [
                        'brandName' => 'Security footer',
                        'columns' => [
                            ['heading' => 'Unsafe links', 'links' => [['label' => 'Unsafe footer', 'url' => 'javascript:alert(6)']]],
                        ],
                    ],
                    'cta' => [
                        'heading' => 'Unsafe CTA section',
                        'actions' => [['label' => 'Unsafe CTA action', 'url' => 'javascript:alert(7)']],
                    ],
                ],
            ],
        ],
    ]);
    $page->setRelation('translation', $translation);

    bindThemeAdapterContext($page, $site);

    $themePage = (new CapellFrontendThemePageAdapter)->currentPage();
    $heroSection = expectPresent($themePage->sections[0] ?? null);
    $listingSection = expectPresent($themePage->sections[1] ?? null);
    $ctaSection = expectPresent($themePage->sections[2] ?? null);
    assert($heroSection instanceof HeroSectionData);
    assert($listingSection instanceof ContentListingSectionData);
    assert($ctaSection instanceof CtaSectionData);

    expect($heroSection->actions)->toBe([['label' => 'Email', 'url' => 'mailto:editor@example.com']])
        ->and($heroSection->mediaUrl)->toBeNull()
        ->and(data_get($listingSection->items, '0.url'))->toBeNull()
        ->and(data_get($listingSection->items, '0.imageUrl'))->toBeNull()
        ->and($themePage->navigation?->items)->toBe([])
        ->and($themePage->navigation?->ctaUrl)->toBeNull()
        ->and($themePage->footer?->columns)->toBe([])
        ->and($ctaSection->actions)->toBe([]);
});

it('falls back to a public hero, default navigation, and default footer without render data', function (): void {
    $site = Site::factory()->make(['name' => 'Default Site']);
    $translation = Translation::factory()->make([
        'title' => 'Fallback title',
        'content' => '<p>This body copy becomes the short public summary for the fallback hero.</p>',
        'meta' => [],
    ]);
    $page = Page::factory()->make(['name' => 'Untitled', 'meta' => []]);
    $page->setRelation('translation', $translation);

    bindThemeAdapterContext($page, $site);

    $themePage = (new CapellFrontendThemePageAdapter)->currentPage();
    $heroSection = expectPresent($themePage->sections[0] ?? null);
    assert($heroSection instanceof HeroSectionData);

    expect($themePage->sections)->toHaveCount(1)
        ->and($heroSection)->toBeInstanceOf(HeroSectionData::class)
        ->and($heroSection->heading)->toBe('Fallback title')
        ->and($heroSection->summary)->toContain('body copy')
        ->and($themePage->navigation?->brandName)->toBe('Default Site')
        ->and($themePage->navigation?->items)->toBe([['label' => 'Home', 'url' => '/']])
        ->and($themePage->footer?->summary)->toBeNull()
        ->and($themePage->footer?->columns)->toBe([]);
});

it('builds an ordered section list from render data, mapping known and signature types', function (): void {
    $site = Site::factory()->make(['name' => 'Ordered Site']);
    $translation = Translation::factory()->make(['title' => 'Ordered page', 'content' => '<p>Body</p>']);
    $page = Page::factory()->make([
        'name' => 'Ordered page',
        'meta' => [
            'theme' => [
                'render_data' => [
                    'sections' => [
                        ['type' => 'hero', 'heading' => 'Studio hero', 'actions' => [['label' => 'Work', 'url' => '#work', 'style' => 'primary']]],
                        ['type' => 'services', 'heading' => 'What we do', 'services' => [['title' => 'Brand', 'discipline' => 'Strategy']]],
                        ['type' => 'project-showcase', 'heading' => 'Selected work', 'items' => [['title' => 'Meridian', 'discipline' => 'Brand']]],
                        ['type' => 'proof', 'heading' => 'Proof', 'items' => [['metric' => '40+', 'name' => 'Brands']]],
                        ['type' => 'cta', 'heading' => 'Work with us', 'actions' => [['label' => 'Start', 'url' => '#contact']]],
                    ],
                ],
            ],
        ],
    ]);
    $page->setRelation('translation', $translation);

    bindThemeAdapterContext($page, $site);

    $themePage = (new CapellFrontendThemePageAdapter)->currentPage();

    expect(array_map(fn (object $section): string => $section->key(), $themePage->sections))->toBe([
        'hero',
        'services',
        'project-showcase',
        'proof',
        'cta',
    ])
        ->and($themePage->sections[0])->toBeInstanceOf(HeroSectionData::class)
        ->and($themePage->sections[1])->toBeInstanceOf(GenericSectionData::class)
        ->and($themePage->sections[2])->toBeInstanceOf(GenericSectionData::class)
        ->and($themePage->sections[3])->toBeInstanceOf(ProofSectionData::class)
        ->and($themePage->sections[4])->toBeInstanceOf(CtaSectionData::class);
});

it('carries signature section payload and a content-listing fallback on generic sections', function (): void {
    $site = Site::factory()->make(['name' => 'Signature Site']);
    $translation = Translation::factory()->make(['title' => 'Signature page', 'content' => '<p>Body</p>']);
    $page = Page::factory()->make([
        'name' => 'Signature page',
        'meta' => [
            'theme' => [
                'render_data' => [
                    'sections' => [
                        [
                            'type' => 'case-study',
                            'heading' => 'Rebuilding Meridian',
                            'client' => 'Meridian',
                            'metrics' => [['label' => 'Launch', 'value' => '12 wks']],
                        ],
                    ],
                ],
            ],
        ],
    ]);
    $page->setRelation('translation', $translation);

    bindThemeAdapterContext($page, $site);

    $themePage = (new CapellFrontendThemePageAdapter)->currentPage();
    $section = $themePage->sections[0];
    assert($section instanceof GenericSectionData);

    expect($section->key())->toBe('case-study')
        ->and($section->fallbackKey())->toBe('content-listing')
        ->and($section->heading)->toBe('Rebuilding Meridian')
        ->and($section->client)->toBe('Meridian')
        ->and($section->metrics)->toBe([['label' => 'Launch', 'value' => '12 wks']]);
});

it('ignores an ordered section list that is not a plain list and keeps implicit behavior', function (): void {
    $site = Site::factory()->make(['name' => 'Implicit Site']);
    $translation = Translation::factory()->make(['title' => 'Implicit page', 'content' => '<p>Body</p>']);
    $page = Page::factory()->make([
        'name' => 'Implicit page',
        'meta' => [
            'theme' => [
                'render_data' => [
                    'sections' => ['heading' => 'not a list'],
                    'hero' => ['heading' => 'Implicit hero'],
                ],
            ],
        ],
    ]);
    $page->setRelation('translation', $translation);

    bindThemeAdapterContext($page, $site);

    $themePage = (new CapellFrontendThemePageAdapter)->currentPage();

    expect($themePage->sections)->toHaveCount(1)
        ->and($themePage->sections[0])->toBeInstanceOf(HeroSectionData::class);
});

function bindThemeAdapterContext(?Pageable $page, ?Site $site): void
{
    app()->instance(ThemeRuntimeSettings::class, new class implements ThemeRuntimeSettings
    {
        public function activeTheme(): string
        {
            return 'testing-theme';
        }

        public function activePreset(): string
        {
            return 'testing-preset';
        }

        public function brandProfile(): BrandProfileData
        {
            return new BrandProfileData(primaryColor: '#123456');
        }

        public function themeOverrides(): array
        {
            return [];
        }
    });

    app()->instance(CapellFrontendContext::class, new CapellFrontendContext(
        new readonly class($page, $site) implements FrontendContextReader
        {
            public function __construct(
                private ?Pageable $page,
                private ?Site $site,
            ) {}

            public function site(): ?Site
            {
                return $this->site;
            }

            public function language(): ?Language
            {
                return null;
            }

            public function page(): ?Pageable
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
                return [];
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
                return $key === null ? [] : null;
            }
        },
    ));
}
