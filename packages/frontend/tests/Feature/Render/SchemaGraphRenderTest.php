<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\BuildPageSchemaGraphAction;
use Capell\Frontend\Contracts\SchemaGraphContributor;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Support\Render\SchemaGraphContributorRegistry;

beforeEach(function (): void {
    config()->set('capell-frontend.html_cache', false);
    config()->set('capell-frontend.write_html_cache', false);
});

it('renders a deduplicated schema graph for matching page blueprints', function (): void {
    $site = Site::factory()->withTranslations(siteDomainData: [
        'default' => true,
        'domain' => 'example.test',
        'path' => null,
        'scheme' => 'https',
    ])->createOne();
    $blueprint = Blueprint::factory()->page()->createOne(['key' => 'article']);

    Page::factory()
        ->published()
        ->site($site)
        ->type($blueprint)
        ->withTranslations($site->language, ['title' => 'Article'], slug: 'article')
        ->createOne();

    $node = [
        '@type' => 'Article',
        'headline' => 'Hello </script><script>alert(1)</script>',
    ];

    $contributor = new readonly class($node) implements SchemaGraphContributor
    {
        /** @param array<string, mixed> $node */
        public function __construct(private array $node) {}

        public function blueprintKeys(): ?array
        {
            return ['article'];
        }

        public function contribute(FrontendRenderContextData $context): array
        {
            return [$this->node, $this->node];
        }
    };

    app()->instance('test.schema-graph-contributor', $contributor);
    app()->tag('test.schema-graph-contributor', SchemaGraphContributor::TAG);

    $response = $this->get('https://example.test/article')->assertOk();

    $response
        ->assertSee('<script type="application/ld+json">', false)
        ->assertSee('"@type":"Article"', false)
        ->assertSee('Hello \\u003C/script\\u003E\\u003Cscript\\u003Ealert(1)\\u003C/script\\u003E', false)
        ->assertDontSee('Hello </script><script>alert(1)</script>', false);

    expect(substr_count((string) $response->getContent(), '"@type":"Article"'))->toBe(1);
});

it('returns no graph when no contributor matches the page blueprint', function (): void {
    $blueprint = Blueprint::factory()->page()->createOne(['key' => 'landing']);
    $page = Page::factory()->type($blueprint)->createOne();
    $page->load('blueprint');

    resolve(SchemaGraphContributorRegistry::class)->register(
        new class implements SchemaGraphContributor
        {
            public function blueprintKeys(): ?array
            {
                return ['article'];
            }

            public function contribute(FrontendRenderContextData $context): array
            {
                return [['@type' => 'Article']];
            }
        },
    );

    expect(BuildPageSchemaGraphAction::run(
        new FrontendRenderContextData($page, null, null, null, null),
    ))->toBeNull();
});

it('applies contributors that target every blueprint', function (): void {
    $blueprint = Blueprint::factory()->page()->createOne(['key' => 'landing']);
    $page = Page::factory()->type($blueprint)->createOne();
    $page->load('blueprint');

    resolve(SchemaGraphContributorRegistry::class)->register(
        new class implements SchemaGraphContributor
        {
            public function blueprintKeys(): ?array
            {
                return null;
            }

            public function contribute(FrontendRenderContextData $context): array
            {
                return [['@type' => 'WebPage']];
            }
        },
    );

    $graph = BuildPageSchemaGraphAction::run(
        new FrontendRenderContextData($page, null, null, null, null),
    );

    expect($graph?->nodes)->toBe([['@type' => 'WebPage']]);
});
