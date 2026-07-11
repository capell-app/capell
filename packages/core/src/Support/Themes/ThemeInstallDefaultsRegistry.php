<?php

declare(strict_types=1);

namespace Capell\Core\Support\Themes;

use Closure;
use LogicException;

final class ThemeInstallDefaultsRegistry
{
    /** @var array<string, Closure(): void> */
    private array $handlers = [];

    /** @param callable(): void $handler */
    public function register(string $themeKey, callable $handler): void
    {
        if (isset($this->handlers[$themeKey])) {
            throw new LogicException("Theme install defaults are already registered for [{$themeKey}].");
        }

        $this->handlers[$themeKey] = Closure::fromCallable($handler);
    }

    public function install(string $themeKey): bool
    {
        $handler = $this->handlers[$themeKey] ?? null;

        if ($handler === null) {
            return false;
        }

        $handler();

        return true;
    }

    public function has(string $themeKey): bool
    {
        return isset($this->handlers[$themeKey]);
    }
}
