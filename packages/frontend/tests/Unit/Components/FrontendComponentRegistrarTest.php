<?php

declare(strict_types=1);

use Capell\Core\Enums\LivewirePageComponentEnum;
use Capell\Frontend\Contracts\FrontendComponentContributor;
use Capell\Frontend\Data\FrontendComponentContributionData;
use Capell\Frontend\Enums\FrontendComponentTarget;
use Capell\Frontend\Livewire\Page\Page;
use Capell\Frontend\Support\Components\FrontendComponentRegistrar;
use Illuminate\Support\Facades\Config;
use Livewire\Component;

beforeEach(function (): void {
    Config::set('capell-frontend.blade_components', []);
    Config::set('capell-frontend.livewire_components', []);
});

it('builds the configured component maps without contributors', function (): void {
    Config::set('capell-frontend.blade_components', [
        'configured-blade' => 'frontend::components.configured',
        'invalid-blade' => null,
    ]);
    Config::set('capell-frontend.livewire_components', [
        'configured-component' => FrontendRegistrarTestComponent::class,
        10 => FrontendRegistrarTestComponent::class,
        'invalid-component' => null,
        'not-a-livewire-component' => stdClass::class,
    ]);

    $registrar = resolve(FrontendComponentRegistrar::class);

    expect($registrar->bladeComponents())->toBe([
        'configured-blade' => 'frontend::components.configured',
    ])->and($registrar->livewireComponents())->toBe([
        LivewirePageComponentEnum::Default->value => Page::class,
        'configured-component' => FrontendRegistrarTestComponent::class,
    ]);
});

it('maps contributions to both frontend targets', function (): void {
    tagFrontendComponentContributor('both-targets', new class implements FrontendComponentContributor
    {
        public function components(): array
        {
            return [
                new FrontendComponentContributionData(
                    name: 'contributed-blade',
                    component: 'layout-builder::components.contributed',
                    target: FrontendComponentTarget::Blade,
                ),
                new FrontendComponentContributionData(
                    name: 'contributed-livewire',
                    component: FrontendRegistrarTestComponent::class,
                    target: FrontendComponentTarget::Livewire,
                ),
            ];
        }
    });

    $registrar = resolve(FrontendComponentRegistrar::class);

    expect($registrar->bladeComponents())->toBe([
        'contributed-blade' => 'layout-builder::components.contributed',
    ])->and($registrar->livewireComponents())->toBe([
        LivewirePageComponentEnum::Default->value => Page::class,
        'contributed-livewire' => FrontendRegistrarTestComponent::class,
    ]);
});

it('keeps the default maps when a contributor is empty', function (): void {
    tagFrontendComponentContributor('empty', new class implements FrontendComponentContributor
    {
        public function components(): array
        {
            return [];
        }
    });

    $registrar = resolve(FrontendComponentRegistrar::class);

    expect($registrar->bladeComponents())->toBe([])
        ->and($registrar->livewireComponents())->toBe([
            LivewirePageComponentEnum::Default->value => Page::class,
        ]);
});

it('applies contributors in tag order after configured and built-in components', function (): void {
    Config::set('capell-frontend.blade_components', [
        'shared' => 'frontend::components.configured',
    ]);
    Config::set('capell-frontend.livewire_components', [
        LivewirePageComponentEnum::Default->value => FrontendRegistrarTestComponent::class,
    ]);

    tagFrontendComponentContributor('first', new FrontendRegistrarTestContributor(
        blade: 'layout-builder::components.first',
        livewire: FrontendRegistrarTestComponent::class,
    ));
    tagFrontendComponentContributor('second', new FrontendRegistrarTestContributor(
        blade: 'layout-builder::components.second',
        livewire: FrontendRegistrarOverrideTestComponent::class,
    ));

    $registrar = resolve(FrontendComponentRegistrar::class);

    expect($registrar->bladeComponents())->toBe([
        'shared' => 'layout-builder::components.second',
    ])->and($registrar->livewireComponents())->toBe([
        LivewirePageComponentEnum::Default->value => FrontendRegistrarOverrideTestComponent::class,
    ]);
});

it('ignores invalid tagged services and livewire component values', function (): void {
    tagFrontendComponentContributor('invalid-service', new stdClass);
    tagFrontendComponentContributor('invalid-values', new class implements FrontendComponentContributor
    {
        public function components(): array
        {
            return [
                new FrontendComponentContributionData(
                    name: 'invalid-livewire',
                    component: stdClass::class,
                    target: FrontendComponentTarget::Livewire,
                ),
            ];
        }
    });

    expect(resolve(FrontendComponentRegistrar::class)->livewireComponents())->toBe([
        LivewirePageComponentEnum::Default->value => Page::class,
    ]);
});

it('resolves contributor state afresh for each registrar instance', function (): void {
    $first = resolve(FrontendComponentRegistrar::class);

    tagFrontendComponentContributor('late', new class implements FrontendComponentContributor
    {
        public function components(): array
        {
            return [new FrontendComponentContributionData(
                name: 'late-blade',
                component: 'layout-builder::components.late',
                target: FrontendComponentTarget::Blade,
            )];
        }
    });

    $second = resolve(FrontendComponentRegistrar::class);

    expect($second)->not->toBe($first)
        ->and($second->bladeComponents())->toBe([
            'late-blade' => 'layout-builder::components.late',
        ]);
});

function tagFrontendComponentContributor(string $key, object $contributor): void
{
    app()->instance('test.frontend-component-contributor.' . $key, $contributor);
    app()->tag('test.frontend-component-contributor.' . $key, FrontendComponentContributor::TAG);
}

final readonly class FrontendRegistrarTestContributor implements FrontendComponentContributor
{
    /** @param class-string<Component> $livewire */
    public function __construct(
        private string $blade,
        private string $livewire,
    ) {}

    public function components(): array
    {
        return [
            new FrontendComponentContributionData('shared', $this->blade, FrontendComponentTarget::Blade),
            new FrontendComponentContributionData(
                LivewirePageComponentEnum::Default->value,
                $this->livewire,
                FrontendComponentTarget::Livewire,
            ),
        ];
    }
}

final class FrontendRegistrarTestComponent extends Component
{
    public function render(): string
    {
        return '<div></div>';
    }
}

final class FrontendRegistrarOverrideTestComponent extends Component
{
    public function render(): string
    {
        return '<div></div>';
    }
}
