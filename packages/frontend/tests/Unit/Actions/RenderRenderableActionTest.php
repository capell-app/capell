<?php

declare(strict_types=1);

use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Support\Renderables\RenderableRegistry;
use Capell\Core\Support\Renderables\RenderableViewDataContext;
use Capell\Core\Support\Renderables\RenderableViewDataResolver;
use Capell\Frontend\Actions\RenderRenderableAction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

it('renders registered blade renderables with resolved view data', function (): void {
    $viewPath = storage_path('framework/testing/renderable-views');
    File::ensureDirectoryExists($viewPath);
    File::put($viewPath . '/hero.blade.php', '<section>{{ $headline }} {{ $renderKey }} {{ $meta["eyebrow"] }}</section>');
    View::addNamespace('renderable-test', $viewPath);

    resolve(RenderableRegistry::class)->register(new RenderableDefinitionData(
        key: 'renderable.hero',
        type: 'vendor-renderable',
        blade: 'renderable-test::hero',
        viewDataResolver: RenderRenderableActionTestResolver::class,
    ));

    expect(RenderRenderableAction::run(
        type: 'vendor-renderable',
        key: 'renderable.hero',
        asset: renderRenderableActionTestModel(),
        translation: renderRenderableActionTestModel(),
        meta: ['eyebrow' => 'CMS'],
    ))->toContain('Resolver headline renderable.hero CMS');
});

it('renders the public renderable blade component', function (): void {
    $viewPath = storage_path('framework/testing/renderable-component-views');
    File::ensureDirectoryExists($viewPath);
    File::put($viewPath . '/content.blade.php', '<article>{{ $meta["title"] }}</article>');
    View::addNamespace('renderable-component-test', $viewPath);

    resolve(RenderableRegistry::class)->register(new RenderableDefinitionData(
        key: 'renderable.content',
        type: 'vendor-renderable',
        blade: 'renderable-component-test::content',
    ));

    $asset = renderRenderableActionTestModel();
    $translation = renderRenderableActionTestModel();

    $view = $this->blade(
        '<x-capell::renderable type="vendor-renderable" key="renderable.content" :asset="$asset" :translation="$translation" :meta="$meta" />',
        ['asset' => $asset, 'translation' => $translation, 'meta' => ['title' => 'Component title']],
    );

    $view->assertSee('Component title');
});

function renderRenderableActionTestModel(): Model
{
    return new class extends Model
    {
        use HasFactory;

        protected $guarded = [];
    };
}

final class RenderRenderableActionTestResolver implements RenderableViewDataResolver
{
    /**
     * @return array<string, mixed>
     */
    public function data(RenderableViewDataContext $context): array
    {
        return [
            'headline' => 'Resolver headline',
        ];
    }
}
