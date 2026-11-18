<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Exception;
use Throwable;

class ComponentNotFoundException extends Exception
{
    public static function forComponent(string $component, ?Throwable $previous = null): self
    {
        return new self(
            $component . ' component not found. Register it with ComponentRegistry::registerComponent() or check the component name for typos.',
            $previous instanceof Throwable ? (int) $previous->getCode() : 0,
            $previous,
        );
    }

    public static function livewireRegistryMissing(string $component): self
    {
        return new self($component . ' livewire component registry not found. Install Livewire and register the component with it before resolving its view.');
    }

    public static function classMissing(string $component): self
    {
        return new self($component . ' component class not found. Check that the class registered for it exists and is autoloadable.');
    }
}
