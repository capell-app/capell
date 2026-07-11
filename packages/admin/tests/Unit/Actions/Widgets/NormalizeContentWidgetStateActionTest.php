<?php

declare(strict_types=1);

use Capell\Admin\Actions\Widgets\NormalizeContentWidgetStateAction;
use Capell\Admin\Support\Widgets\WidgetDiscovery;
use Capell\Admin\Tests\Fixtures\Widgets\LegacyStateIntegrityFilamentWidget;
use Capell\Admin\Tests\Fixtures\Widgets\StateIntegrityFilamentWidget;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $discovery = resolve(WidgetDiscovery::class);
    $discovery->register(StateIntegrityFilamentWidget::class);
    $discovery->register(LegacyStateIntegrityFilamentWidget::class);
});

it('keeps valid unique identities stable and repairs missing invalid and duplicate identities', function (): void {
    $stableIdentity = (string) Str::uuid();
    $duplicateIdentity = (string) Str::uuid();
    $state = [
        'stable' => widgetState($stableIdentity),
        'missing' => widgetState(null),
        'invalid' => widgetState('not-a-uuid'),
        'first-duplicate' => widgetState($duplicateIdentity),
        'second-duplicate' => widgetState($duplicateIdentity),
    ];

    $normalized = NormalizeContentWidgetStateAction::run($state);
    $identities = array_map(
        static fn (array $widget): string => $widget['data']['__capell']['instance_id'],
        $normalized,
    );

    expect($normalized)->toHaveKeys(array_keys($state))
        ->and($identities['stable'])->toBe($stableIdentity)
        ->and($identities['first-duplicate'])->toBe($duplicateIdentity)
        ->and($identities['second-duplicate'])->not->toBe($duplicateIdentity)
        ->and(array_unique($identities))->toHaveCount(count($identities));

    foreach ($identities as $identity) {
        expect(Str::isUuid($identity))->toBeTrue();
    }
});

it('merges reserved identity without removing presentation interaction or resource state', function (): void {
    $normalized = NormalizeContentWidgetStateAction::run([
        'widget' => [
            'type' => 'capell-app.state-integrity',
            'data' => [
                'title' => 'Example',
                '__capell' => [
                    'presentation' => ['width' => 'wide'],
                    'interactions' => [['event' => 'click']],
                    'resources' => ['strategy' => 'visible'],
                ],
            ],
        ],
    ]);

    expect($normalized['widget']['data']['title'])->toBe('Example')
        ->and($normalized['widget']['data']['__capell']['presentation'])->toBe(['width' => 'wide'])
        ->and($normalized['widget']['data']['__capell']['interactions'])->toBe([['event' => 'click']])
        ->and($normalized['widget']['data']['__capell']['resources'])->toBe(['strategy' => 'visible'])
        ->and(Str::isUuid($normalized['widget']['data']['__capell']['instance_id']))->toBeTrue();
});

it('normalizes registered widgets recursively through nested targets and associative containers', function (): void {
    $normalized = NormalizeContentWidgetStateAction::run([
        'outer' => [
            'type' => 'capell-app.state-integrity',
            'data' => [
                'interactions' => [
                    'primary' => [
                        'target_widget' => widgetState(null),
                    ],
                ],
            ],
        ],
    ]);

    $outerIdentity = $normalized['outer']['data']['__capell']['instance_id'];
    $nestedIdentity = $normalized['outer']['data']['interactions']['primary']['target_widget']['data']['__capell']['instance_id'];

    expect(Str::isUuid($outerIdentity))->toBeTrue()
        ->and(Str::isUuid($nestedIdentity))->toBeTrue()
        ->and($nestedIdentity)->not->toBe($outerIdentity);
});

it('preserves unknown widgets as exact opaque state and does not inspect their nested data', function (): void {
    $unknown = [
        'type' => 'missing.vendor-widget',
        'data' => [
            '__capell' => ['instance_id' => 'leave-me-alone'],
            'nested' => widgetState(null),
        ],
        'extension-owned-key' => ['any' => 'value'],
    ];

    $normalized = NormalizeContentWidgetStateAction::run(['unknown' => $unknown]);

    expect($normalized)->toBe(['unknown' => $unknown]);
});

it('preserves unregistered widget blocks with missing or scalar data as exact opaque state', function (): void {
    $missingData = [
        'type' => 'missing.without-data',
        'nested' => widgetState(null),
        'extension-owned-key' => ['any' => 'value'],
    ];
    $scalarData = [
        'type' => 'missing.scalar-data',
        'data' => 'extension-owned-scalar',
        'nested' => widgetState(null),
    ];

    $normalized = NormalizeContentWidgetStateAction::run([
        'missing' => $missingData,
        'scalar' => $scalarData,
    ]);

    expect($normalized)->toBe([
        'missing' => $missingData,
        'scalar' => $scalarData,
    ]);
});

it('keeps legacy registered widgets identity-aware without adding an extension state version', function (): void {
    $normalized = NormalizeContentWidgetStateAction::run([
        ['type' => 'legacy-state-integrity', 'data' => []],
    ]);

    expect(Str::isUuid($normalized[0]['data']['__capell']['instance_id']))->toBeTrue()
        ->and($normalized[0]['data']['__capell'])->not->toHaveKey('state_version');
});

it('leaves excessively deep and wide state atomically unchanged', function (): void {
    $deepState = widgetState(null);

    for ($depth = 0; $depth < 70; $depth++) {
        $deepState = ['container' => $deepState];
    }

    $wideState = [];

    for ($index = 0; $index <= 10_000; $index++) {
        $wideState[$index] = ['value' => $index];
    }

    expect(NormalizeContentWidgetStateAction::run($deepState))->toBe($deepState)
        ->and(NormalizeContentWidgetStateAction::run($wideState))->toBe($wideState);
});

/** @return array{type: string, data: array<string, mixed>} */
function widgetState(?string $identity): array
{
    $capell = $identity === null ? [] : ['instance_id' => $identity];

    return [
        'type' => 'capell-app.state-integrity',
        'data' => ['__capell' => $capell],
    ];
}
