<?php

declare(strict_types=1);

use Capell\Core\Data\Extensions\ExtensionOrderDiagnosticData;
use Capell\Core\Support\Extensions\ExtensionOrderResolver;
use Capell\Core\Support\Extensions\ExtensionPosition;

it('orders relative positions and preserves registration order for ties', function (): void {
    $items = [
        ['key' => 'middle', 'position' => ExtensionPosition::priority(10)],
        ['key' => 'first', 'position' => ExtensionPosition::first()],
        ['key' => 'before', 'position' => ExtensionPosition::before('middle')],
        ['key' => 'after', 'position' => ExtensionPosition::after('middle')],
        ['key' => 'tie-a', 'position' => ExtensionPosition::priority(20)],
        ['key' => 'tie-b', 'position' => ExtensionPosition::priority(20)],
        ['key' => 'last', 'position' => ExtensionPosition::last()],
    ];

    $resolver = new ExtensionOrderResolver;
    $result = $resolver->resolve(
        $items,
        static fn (array $item): string => $item['key'],
        static fn (array $item): ExtensionPosition => $item['position'],
    );

    expect(array_column($result, 'key'))->toBe([
        'first', 'before', 'middle', 'after', 'tie-a', 'tie-b', 'last',
    ]);
});

it('falls back deterministically for missing anchors and cycles', function (): void {
    $items = [
        ['key' => 'a', 'position' => ExtensionPosition::after('missing')],
        ['key' => 'b', 'position' => ExtensionPosition::after('c')],
        ['key' => 'c', 'position' => ExtensionPosition::after('b')],
    ];

    $resolver = new ExtensionOrderResolver;
    $result = $resolver->resolve(
        $items,
        static fn (array $item): string => $item['key'],
        static fn (array $item): ExtensionPosition => $item['position'],
    );

    expect(array_column($result, 'key'))->toBe(['a', 'b', 'c'])
        ->and(array_map(static fn (ExtensionOrderDiagnosticData $diagnostic): string => $diagnostic->type, $resolver->diagnostics()))->toBe(['missing-anchor', 'cycle']);
});

it('rejects duplicate and empty keys', function (): void {
    expect(fn (): array => (new ExtensionOrderResolver)->resolve(
        [['key' => 'same'], ['key' => 'same']],
        static fn (array $item): string => $item['key'],
    ))->toThrow(LogicException::class);

    expect(fn (): array => (new ExtensionOrderResolver)->resolve(
        [['key' => '']],
        static fn (array $item): string => $item['key'],
    ))->toThrow(LogicException::class);

    expect(fn (): ExtensionPosition => ExtensionPosition::before(' '))
        ->toThrow(InvalidArgumentException::class);
});
