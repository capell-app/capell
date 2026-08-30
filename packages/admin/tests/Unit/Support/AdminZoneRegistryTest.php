<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminZoneContextData;
use Capell\Admin\Data\AdminZoneContributionData;
use Capell\Admin\Enums\AdminZone;
use Capell\Admin\Support\AdminZoneRegistry;
use Capell\Core\Support\Extensions\ExtensionPosition;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/** @property string $column */
#[AllowDynamicProperties]
final class AdminZoneDynamicState {}

function adminZoneContext(?Authenticatable $user = null): AdminZoneContextData
{
    return new AdminZoneContextData(AdminZone::PageListTableColumns, 'tests.page-list', $user ?? auth()->user());
}

function adminZoneContribution(
    string $key,
    Closure $resolver,
    ?ExtensionPosition $position = null,
    ?string $permission = null,
    ?Closure $visibility = null,
    string $owner = 'tests/admin',
): AdminZoneContributionData {
    return new AdminZoneContributionData(
        zone: AdminZone::PageListTableColumns,
        key: $key,
        resolver: $resolver,
        position: $position,
        permission: $permission,
        visibility: $visibility,
        owner: $owner,
        source: 'AdminZoneRegistryTest',
    );
}

it('interleaves built-in and package columns through the shared ordering resolver', function (): void {
    $registry = new AdminZoneRegistry;

    $builtIn = adminZoneContribution(
        'core.page-list.columns',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('core_column')],
        ExtensionPosition::first(),
        owner: 'capell-app/admin',
    );
    $packageBefore = adminZoneContribution(
        'package.before',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('package_before')],
        ExtensionPosition::before('core.page-list.columns'),
        owner: 'vendor/reference-extension',
    );
    $packageAfter = adminZoneContribution(
        'package.after',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('package_after')],
        ExtensionPosition::after('core.page-list.columns'),
        owner: 'vendor/reference-extension',
    );

    $registry->register($builtIn);
    $registry->register($packageAfter);
    $registry->register($packageBefore);

    $columns = $registry->resolve(AdminZone::PageListTableColumns, adminZoneContext());

    expect(array_map(static fn (TextColumn $column): string => $column->getName(), $columns))
        ->toBe(['package_before', 'core_column', 'package_after']);
});

it('exposes missing-anchor diagnostics without changing deterministic fallback order', function (): void {
    $registry = new AdminZoneRegistry;
    $registry->register(adminZoneContribution(
        'package.column',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('package_column')],
        ExtensionPosition::after('missing.anchor'),
    ));

    $columns = $registry->resolve(AdminZone::PageListTableColumns, adminZoneContext());

    expect($columns[0]->getName())->toBe('package_column')
        ->and($registry->orderingDiagnostics(AdminZone::PageListTableColumns)[0]->type)->toBe('missing-anchor');
});

it('enforces idempotence, explicit replacement, duplicate ownership diagnostics, and freeze', function (): void {
    $registry = new AdminZoneRegistry;
    $original = adminZoneContribution(
        'same.key',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('original')],
    );

    $registry->register($original);
    $registry->register($original);

    $equivalent = adminZoneContribution(
        'equivalent.key',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('equivalent')],
    );
    $freshEquivalent = adminZoneContribution(
        'equivalent.key',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('equivalent')],
    );
    $registry->register($equivalent);
    $registry->register($freshEquivalent);

    expect($registry->contributions(AdminZone::PageListTableColumns))->toHaveCount(2);
    expect($registry->contributions(AdminZone::PageListTableColumns)[1])->toBe($equivalent);

    expect(function () use ($registry): void {
        $registry->register(adminZoneContribution(
            'equivalent.key',
            static fn (AdminZoneContextData $context): array => [TextColumn::make('different-payload')],
        ));
    })->toThrow(LogicException::class, 'AdminZoneRegistryTest');

    expect(function () use ($registry): void {
        $registry->register(adminZoneContribution(
            'same.key',
            static fn (AdminZoneContextData $context): array => [TextColumn::make('conflict')],
            owner: 'vendor/other',
        ));
    })->toThrow(LogicException::class, 'vendor/other');

    $replacement = adminZoneContribution(
        'same.key',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('replacement')],
        owner: 'vendor/other',
    );
    $registry->replace($replacement);
    expect($registry->resolve(AdminZone::PageListTableColumns, adminZoneContext())[0]->getName())->toBe('replacement');

    $registry->freeze();
    expect(function () use ($registry): void {
        $registry->register(adminZoneContribution(
            'late.key',
            static fn (AdminZoneContextData $context): array => [TextColumn::make('late')],
            owner: 'vendor/late',
        ));
    })->toThrow(LogicException::class, 'vendor/late');
});

it('does not treat resolver literals or bound object state as equivalent', function (): void {
    $registry = new AdminZoneRegistry;

    $registry->register(adminZoneContribution(
        'literal.key',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('literal value')],
    ));

    expect(function () use ($registry): void {
        $registry->register(adminZoneContribution(
            'literal.key',
            static fn (AdminZoneContextData $context): array => [TextColumn::make('literal  value')],
        ));
    })->toThrow(LogicException::class, 'AdminZoneRegistryTest');

    $makeBoundResolver = static function (string $column): Closure {
        $boundObject = new readonly class($column)
        {
            public function __construct(public string $column) {}
        };

        $resolver = Closure::bind(
            fn (AdminZoneContextData $context): array => [TextColumn::make($this->column)],
            $boundObject,
        );

        throw_unless($resolver instanceof Closure, LogicException::class, 'Expected bound Admin zone resolver closure.');

        return $resolver;
    };

    $registry->register(adminZoneContribution('bound.key', $makeBoundResolver('bound-one')));

    expect(function () use ($registry, $makeBoundResolver): void {
        $registry->register(adminZoneContribution('bound.key', $makeBoundResolver('bound-two')));
    })->toThrow(LogicException::class, 'AdminZoneRegistryTest');

    $makeDynamicResolver = static function (string $column): Closure {
        $boundObject = new AdminZoneDynamicState;
        $boundObject->column = $column;

        $resolver = Closure::bind(
            fn (AdminZoneContextData $context): array => [TextColumn::make($this->column)],
            $boundObject,
        );

        throw_unless($resolver instanceof Closure, LogicException::class, 'Expected dynamic Admin zone resolver closure.');

        return $resolver;
    };

    $registry->register(adminZoneContribution('dynamic-bound.key', $makeDynamicResolver('dynamic-one')));

    expect(function () use ($registry, $makeDynamicResolver): void {
        $registry->register(adminZoneContribution('dynamic-bound.key', $makeDynamicResolver('dynamic-two')));
    })->toThrow(LogicException::class, 'AdminZoneRegistryTest');
});

it('distinguishes same-line literals and supports equivalent first-class callables', function (): void {
    $registry = new AdminZoneRegistry;
    [$first, $second] = [static fn (): array => [TextColumn::make('a')], static fn (): array => [TextColumn::make('b')]];

    $registry->register(adminZoneContribution('same-line.key', $first));

    expect(function () use ($registry, $second): void {
        $registry->register(adminZoneContribution('same-line.key', $second));
    })->toThrow(LogicException::class, 'AdminZoneRegistryTest');

    $provider = new readonly class('first-class')
    {
        public function __construct(private string $column) {}

        /** @return list<TextColumn> */
        public function columns(AdminZoneContextData $context): array
        {
            return [TextColumn::make($this->column)];
        }
    };

    $registry->register(adminZoneContribution('first-class.key', $provider->columns(...)));
    $registry->register(adminZoneContribution('first-class.key', $provider->columns(...)));

    expect($registry->contributions(AdminZone::PageListTableColumns))->toHaveCount(2);
});

it('fails closed for recursive resolver captures', function (): void {
    $recursive = [];
    $recursive['self'] = &$recursive;

    $registry = new AdminZoneRegistry;
    $registry->register(adminZoneContribution(
        'recursive.key',
        static function (AdminZoneContextData $context) use (&$recursive): array {
            return [TextColumn::make('recursive-' . count($recursive))];
        },
    ));

    expect(function () use ($registry, &$recursive): void {
        $registry->register(adminZoneContribution(
            'recursive.key',
            static function (AdminZoneContextData $context) use (&$recursive): array {
                return [TextColumn::make('recursive-' . count($recursive))];
            },
        ));
    })->toThrow(LogicException::class, 'AdminZoneRegistryTest');
});

it('fails closed when a resolver re-enters the same zone', function (): void {
    $registry = new AdminZoneRegistry;

    $registry->register(adminZoneContribution(
        'recursive-resolution.key',
        fn (AdminZoneContextData $context): array => $registry->resolve(AdminZone::PageListTableColumns, $context),
    ));

    expect(fn (): array => $registry->resolve(AdminZone::PageListTableColumns, adminZoneContext()))
        ->toThrow(LogicException::class, 'Recursive Admin zone resolution detected');
});

it('fails closed when callable identity contains an object and closure cycle', function (): void {
    $cycle = new stdClass;
    $resolver = Closure::bind(
        fn (AdminZoneContextData $context): array => [TextColumn::make($cycle->resolver instanceof Closure ? 'cyclic' : 'other')],
        $cycle,
    );

    throw_unless($resolver instanceof Closure, LogicException::class, 'Expected cyclic Admin zone resolver closure.');

    $cycle->resolver = $resolver;
    $registry = new AdminZoneRegistry;
    $registry->register(adminZoneContribution('cyclic-identity.key', $resolver));

    expect(function () use ($registry, $resolver): void {
        $registry->register(adminZoneContribution('cyclic-identity.key', $resolver));
    })->toThrow(LogicException::class, 'AdminZoneRegistryTest');
});

it('fails closed when callable identity contains mutually recursive closures', function (): void {
    $first = null;
    $second = null;
    $first = static function (AdminZoneContextData $context) use (&$second): array {
        return [$second];
    };
    $second = static function (AdminZoneContextData $context) use (&$first): array {
        return [$first];
    };

    $registry = new AdminZoneRegistry;
    $registry->register(adminZoneContribution('mutual-closure.key', $first));

    expect(function () use ($registry, $first): void {
        $registry->register(adminZoneContribution('mutual-closure.key', $first));
    })->toThrow(LogicException::class, 'AdminZoneRegistryTest');
});

it('filters contributions by declared permission and visibility', function (): void {
    test()->actingAsAdmin();

    $context = adminZoneContext();
    $seenVisibilityContext = null;
    $seenResolverContext = null;

    Gate::define('tests.view-admin-zone', static fn (): bool => true);

    $registry = new AdminZoneRegistry;
    $registry->register(adminZoneContribution(
        'permitted',
        static function (AdminZoneContextData $callbackContext) use (&$seenResolverContext): array {
            $seenResolverContext = $callbackContext;

            return [TextColumn::make('permitted')];
        },
        permission: 'tests.view-admin-zone',
        visibility: static function (AdminZoneContextData $callbackContext) use (&$seenVisibilityContext): bool {
            $seenVisibilityContext = $callbackContext;

            return true;
        },
    ));
    $registry->register(adminZoneContribution(
        'hidden',
        static fn (AdminZoneContextData $context): array => [TextColumn::make('hidden')],
        visibility: static fn (AdminZoneContextData $context): bool => false,
    ));

    $columns = $registry->resolve(AdminZone::PageListTableColumns, $context);

    expect($columns)->toHaveCount(1)
        ->and($columns[0]->getName())->toBe('permitted')
        ->and($seenVisibilityContext)->toBe($context)
        ->and($seenResolverContext)->toBe($context);
});

it('suppresses a denied contribution before resolving it and propagates the authenticated context', function (): void {
    $user = test()->createUser();
    $seenUser = null;
    $resolved = false;

    Gate::define('tests.denied-admin-zone', function (mixed $actor) use (&$seenUser): bool {
        $seenUser = $actor;

        return false;
    });

    $registry = new AdminZoneRegistry;
    $registry->register(adminZoneContribution(
        'denied',
        static function (AdminZoneContextData $context) use (&$resolved): array {
            $resolved = true;

            return [TextColumn::make('denied')];
        },
        permission: 'tests.denied-admin-zone',
    ));

    expect($registry->resolve(AdminZone::PageListTableColumns, adminZoneContext($user)))->toBe([])
        ->and($resolved)->toBeFalse()
        ->and($seenUser)->toBe($user);
});

it('suppresses a permissioned contribution for anonymous context and propagates null to the gate', function (): void {
    $seenUser = false;
    $resolved = false;

    Gate::define('tests.anonymous-admin-zone', function (mixed $actor) use (&$seenUser): bool {
        $seenUser = $actor !== null;

        return false;
    });

    $registry = new AdminZoneRegistry;
    $registry->register(adminZoneContribution(
        'anonymous',
        static function (AdminZoneContextData $context) use (&$resolved): array {
            $resolved = true;

            return [TextColumn::make('anonymous')];
        },
        permission: 'tests.anonymous-admin-zone',
    ));

    expect($registry->resolve(
        AdminZone::PageListTableColumns,
        new AdminZoneContextData(AdminZone::PageListTableColumns, 'tests.anonymous'),
    ))->toBe([])
        ->and($resolved)->toBeFalse()
        ->and($seenUser)->toBeFalse();
});

it('rejects values outside the zone contract', function (): void {
    $registry = new AdminZoneRegistry;
    $registry->register(adminZoneContribution(
        'invalid',
        static fn (AdminZoneContextData $context): array => ['not a column'],
    ));

    expect(fn (): array => $registry->resolve(AdminZone::PageListTableColumns, adminZoneContext()))
        ->toThrow(LogicException::class, 'returned [string]');
});
