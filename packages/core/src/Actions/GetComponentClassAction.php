<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\BladeComponentResolverInterface;
use Exception;
use Illuminate\View\Compilers\ComponentTagCompiler;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Resolves a Blade or Livewire component tag to its PHP class name or view path.
 *
 * @method static string run(string $component, bool $livewire = false)
 */
class GetComponentClassAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly ComponentTagCompiler $bladeCompiler,
        private readonly BladeComponentResolverInterface $bladeResolver,
    ) {}

    public function handle(string $component, bool $livewire = false): string
    {
        if ($livewire) {
            return $this->resolveLivewire($component);
        }

        $class = $this->resolveBladeAlias($component);
        if ($class !== null) {
            return $class;
        }

        $class = $this->resolveBladeNamespace($component);
        if ($class !== null) {
            return $class;
        }

        return $this->bladeCompiler->componentClass($component);
    }

    private function classExists(string $class): bool
    {
        return class_exists($class);
    }

    private function resolveLivewire(string $component): string
    {
        $livewireRegistry = $this->livewireRegistry();

        try {
            if (method_exists($livewireRegistry, 'resolveClassComponentClassName')) {
                $componentClass = $livewireRegistry->resolveClassComponentClassName($component);
            } elseif (method_exists($livewireRegistry, 'getClass')) {
                $componentClass = $livewireRegistry->getClass($component);
            } else {
                $componentClass = null;
            }
        } catch (Throwable $throwable) {
            throw new Exception($component . ' livewire component not found.', $throwable->getCode(), previous: $throwable);
        }

        if ($componentClass !== null) {
            return $componentClass;
        }

        throw new Exception($component . ' livewire component not found.');
    }

    private function livewireRegistry(): object
    {
        if (app()->bound('livewire.finder')) {
            return resolve('livewire.finder');
        }

        if (app()->bound('Livewire\\Mechanisms\\ComponentRegistry')) {
            return resolve('Livewire\\Mechanisms\\ComponentRegistry');
        }

        throw new Exception('Livewire component registry is not available.');
    }

    private function resolveBladeAlias(string $component): ?string
    {
        $aliases = $this->bladeResolver->getClassComponentAliases();

        return $aliases[$component] ?? null;
    }

    private function resolveBladeNamespace(string $component): ?string
    {
        if (! str_contains($component, '::')) {
            return null;
        }

        [$prefix, $tag] = explode('::', $component, 2);
        $segments = array_map(
            fn (string $part): string => str_replace(' ', '', ucwords(str_replace(['-', '.'], ' ', $part))),
            explode('.', $tag),
        );
        $namespaces = $this->bladeResolver->getClassComponentNamespaces();
        if (isset($namespaces[$prefix])) {
            $class = $namespaces[$prefix] . '\\' . implode('\\', $segments);
            if ($this->classExists($class)) {
                return $class;
            }
        }

        return null;
    }
}
