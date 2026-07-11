<?php

declare(strict_types=1);

use Capell\Core\Actions\GetComponentClassAction;
use Capell\Core\Contracts\BladeComponentResolverInterface;
use Capell\Core\Tests\Support\View\Components\PackageAlert;
use Illuminate\View\Compilers\ComponentTagCompiler;

function makeComponentClassAction(array $aliases = [], array $namespaces = [], ?object $livewireRegistry = null): GetComponentClassAction
{
    app()->instance('livewire.finder', $livewireRegistry ?? new class
    {
        public function resolveClassComponentClassName(string $component): ?string
        {
            return null;
        }
    });

    return new GetComponentClassAction(
        resolve(ComponentTagCompiler::class),
        new readonly class($aliases, $namespaces) implements BladeComponentResolverInterface
        {
            /**
             * @param  array<string, class-string>  $aliases
             * @param  array<string, string>  $namespaces
             */
            public function __construct(
                private array $aliases,
                private array $namespaces,
            ) {}

            /**
             * @return array<string, class-string>
             */
            public function getClassComponentAliases(): array
            {
                return $this->aliases;
            }

            /**
             * @return array<string, string>
             */
            public function getClassComponentNamespaces(): array
            {
                return $this->namespaces;
            }
        },
    );
}

it('resolves registered blade component aliases before falling back to compilation', function (): void {
    $action = makeComponentClassAction([
        'hero-card' => 'App\\View\\Components\\HeroCard',
    ]);

    expect($action->handle('hero-card'))->toBe('App\\View\\Components\\HeroCard');
});

it('resolves namespaced blade components when the generated class exists', function (): void {
    $action = makeComponentClassAction(
        namespaces: [
            'package' => 'Capell\\Core\\Tests\\Support\\View\\Components',
        ],
    );

    expect($action->handle('package::package-alert'))->toBe(PackageAlert::class);
});

it('resolves livewire components through the registered livewire finder', function (): void {
    $action = makeComponentClassAction(livewireRegistry: new class
    {
        public function resolveClassComponentClassName(string $component): ?string
        {
            return $component === 'admin.widget'
                ? 'App\\Livewire\\AdminWidget'
                : null;
        }
    });

    expect($action->handle('admin.widget', livewire: true))->toBe('App\\Livewire\\AdminWidget');
});

it('resolves livewire components through registry objects that expose getClass', function (): void {
    $action = makeComponentClassAction(livewireRegistry: new class
    {
        public function getClass(string $component): ?string
        {
            return $component === 'admin.legacy-widget'
                ? 'App\\Livewire\\LegacyWidget'
                : null;
        }
    });

    expect($action->handle('admin.legacy-widget', livewire: true))->toBe('App\\Livewire\\LegacyWidget');
});

it('fails clearly when a livewire component cannot be resolved', function (): void {
    $action = makeComponentClassAction();

    $action->handle('missing.widget', livewire: true);
})->throws(Exception::class, 'missing.widget livewire component not found.');
