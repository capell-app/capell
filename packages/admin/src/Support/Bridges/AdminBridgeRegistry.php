<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Bridges;

use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Illuminate\Support\Facades\App;

final class AdminBridgeRegistry
{
    /** @var array<string, array<class-string<AdminBridge>, class-string<AdminBridge>>> */
    private array $bridges = [];

    /**
     * @param  class-string<AdminBridge>  $bridgeClass
     */
    public function register(string $packageName, string $bridgeClass): void
    {
        $this->bridges[$packageName][$bridgeClass] = $bridgeClass;
    }

    /**
     * @return list<class-string<AdminBridge>>
     */
    public function classes(string $packageName): array
    {
        return array_values($this->bridges[$packageName] ?? []);
    }

    /** @return list<string> */
    public function packageNames(): array
    {
        return array_keys($this->bridges);
    }

    /**
     * @return list<AdminBridge>
     */
    public function enabledBridges(AdminBridgeContextData $context): array
    {
        return array_values(collect($this->classes($context->packageName))
            ->map(static fn (string $bridgeClass): AdminBridge => App::make($bridgeClass))
            ->filter(static fn (AdminBridge $bridge): bool => $bridge->isEnabled($context))
            ->values()
            ->all());
    }

    public function clear(): void
    {
        $this->bridges = [];
    }
}
