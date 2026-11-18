<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Exceptions\ComponentNotFoundException;
use Exception;
use Illuminate\Support\Facades\View;
use Illuminate\View\Compilers\ComponentTagCompiler;
use InvalidArgumentException;
use Livewire\Finder\Finder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Resolves a Blade or Livewire component tag to its PHP class name or view path.
 *
 * @throws ComponentNotFoundException
 *
 * @method static string run(string $component, bool $livewire = false)
 */
class GetComponentViewPathAction
{
    use AsFake;
    use AsObject;

    public function handle(string $component, bool $livewire = false): string
    {
        try {
            if (! $livewire) {
                $view = resolve(ComponentTagCompiler::class)->componentClass($component);
            }

            if ($livewire) {
                // Livewire v4
                if (class_exists(Finder::class) && app()->bound('livewire.finder')) {
                    $componentClass = resolve('livewire.finder')
                        ->resolveClassComponentClassName($component);
                } elseif (app()->bound('Livewire\\Mechanisms\\ComponentRegistry')) {
                    $componentClass = resolve('Livewire\\Mechanisms\\ComponentRegistry')->getClass($component);
                } else {
                    throw ComponentNotFoundException::livewireRegistryMissing($component);
                }

                if ($componentClass === null || ! class_exists($componentClass)) {
                    throw ComponentNotFoundException::classMissing($component);
                }

                $view = $componentClass::getViewName();
            }
        } catch (InvalidArgumentException) {
            throw ComponentNotFoundException::forComponent($component);
        } catch (Exception $exception) {
            throw_if($exception instanceof ComponentNotFoundException, $exception);

            throw ComponentNotFoundException::forComponent($component, $exception);
        }

        return View::getFinder()->find($view);
    }
}
