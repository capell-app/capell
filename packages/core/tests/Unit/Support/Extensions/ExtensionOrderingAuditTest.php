<?php

declare(strict_types=1);

use Capell\Core\Data\Extensions\ExtensionOrderDiagnosticData;
use Capell\Core\Support\Extensions\ExtensionOrderingAudit;

it('collects diagnostics from sources in deterministic order', function (): void {
    $audit = new ExtensionOrderingAudit;
    $audit->register('z-source', static fn (): array => [new ExtensionOrderDiagnosticData('cycle', 'z-key')]);
    $audit->register('a-source', static fn (): array => [new ExtensionOrderDiagnosticData('missing-anchor', 'a-key', 'missing')]);

    expect($audit->diagnostics())->toMatchArray([
        [
            'source' => 'a-source',
            'diagnostic' => new ExtensionOrderDiagnosticData('missing-anchor', 'a-key', 'missing'),
        ],
        [
            'source' => 'z-source',
            'diagnostic' => new ExtensionOrderDiagnosticData('cycle', 'z-key'),
        ],
    ]);
});

it('rejects distinct duplicate source registration', function (): void {
    $audit = new ExtensionOrderingAudit;
    $audit->register('duplicate-source', static fn (): array => []);

    expect(fn () => $audit->register('duplicate-source', static fn (): array => []))
        ->toThrow(LogicException::class, 'already registered');
});
