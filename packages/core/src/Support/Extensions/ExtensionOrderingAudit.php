<?php

declare(strict_types=1);

namespace Capell\Core\Support\Extensions;

use Capell\Core\Data\Extensions\ExtensionOrderDiagnosticData;
use Closure;
use LogicException;

final class ExtensionOrderingAudit
{
    /** @var array<string, Closure(): list<ExtensionOrderDiagnosticData>> */
    private array $sources = [];

    /**
     * @param  Closure(): list<ExtensionOrderDiagnosticData>  $diagnostics
     */
    public function register(string $source, Closure $diagnostics): void
    {
        if (array_key_exists($source, $this->sources)) {
            throw new LogicException(sprintf('Extension ordering audit source [%s] is already registered.', $source));
        }

        $this->sources[$source] = $diagnostics;
    }

    public function hasSource(string $source): bool
    {
        return array_key_exists($source, $this->sources);
    }

    /**
     * @return list<array{source: string, diagnostic: ExtensionOrderDiagnosticData}>
     */
    public function diagnostics(): array
    {
        $sources = $this->sources;
        ksort($sources);

        $results = [];

        foreach ($sources as $source => $diagnostics) {
            foreach ($diagnostics() as $diagnostic) {
                $results[] = [
                    'source' => $source,
                    'diagnostic' => $diagnostic,
                ];
            }
        }

        return $results;
    }
}
