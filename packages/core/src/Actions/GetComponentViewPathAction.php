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
                    throw new ComponentNotFoundException($component . ' livewire component registry not found.');
                }

                throw_if($componentClass === null || ! class_exists($componentClass), ComponentNotFoundException::class, $component . ' component class not found.');

                $view = $componentClass::getViewName();
            }
        } catch (InvalidArgumentException) {
            throw new ComponentNotFoundException($component . ' component not found.');
        } catch (Exception $exception) {
            throw_if($exception instanceof ComponentNotFoundException, $exception);

            throw new ComponentNotFoundException($component . ' component not found.', $exception->getCode(), $exception);
        }

        return View::getFinder()->find($view);
    }
}
