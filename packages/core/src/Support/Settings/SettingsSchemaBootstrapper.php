<?php

declare(strict_types=1);

namespace Capell\Core\Support\Settings;

use Closure;

class SettingsSchemaBootstrapper
{
    /** @var array<Closure> */
    private array $callbacks = [];

    public function __construct(private readonly SettingsSchemaRegistry $registry) {}

    /**
     * Register a callback to run after core schemas are registered
     *
     * @param  Closure(SettingsSchemaRegistry): void  $callback
     */
    public function extend(Closure $callback): void
    {
        $this->callbacks[] = $callback;
    }

    /**
     * Execute all registered callbacks
     */
    public function bootstrap(): void
    {
        foreach ($this->callbacks as $callback) {
            $callback($this->registry);
        }
    }
}
