<?php

declare(strict_types=1);

use Capell\Core\Actions\Security\BuildSecurityPostureInventoryAction;
use Capell\Core\Contracts\Security\SecurityInventoryContributor;

it('does not include application keys or unrelated secrets in its inventory', function (): void {
    config(['app.key' => 'a-unique-application-secret', 'mail.password' => 'a-unique-mail-secret']);
    $inventory = BuildSecurityPostureInventoryAction::run();
    $encoded = json_encode($inventory->toArray(), JSON_THROW_ON_ERROR);
    expect($inventory->flag('app.key_present'))->toBeTrue()
        ->and($encoded)->not->toContain('a-unique-application-secret')
        ->and($encoded)->not->toContain('a-unique-mail-secret');
});

it('merges flags from tagged inventory contributors under their own prefix', function (): void {
    app()->instance('test.security.contributor', new class implements SecurityInventoryContributor
    {
        #[Override]
        public function prefix(): string
        {
            return 'password_policy';
        }

        #[Override]
        public function flags(): array
        {
            return ['compromised_checks_enabled' => false, 'minimum_length' => 12];
        }
    });
    app()->tag(['test.security.contributor'], SecurityInventoryContributor::TAG);

    $inventory = BuildSecurityPostureInventoryAction::run();

    expect($inventory->flag('password_policy.compromised_checks_enabled'))->toBeFalse()
        ->and($inventory->flag('password_policy.minimum_length'))->toBe(12)
        ->and($inventory->flag('app.debug'))->toBe(config('app.debug') === true)
        ->and($inventory->failures)->toBe([]);
});

it('drops a contributor that reuses a Core prefix, supplies non-scalar flags or throws, and records each as a failure', function (): void {
    $corePrefix = new class implements SecurityInventoryContributor
    {
        #[Override]
        public function prefix(): string
        {
            return 'app';
        }

        #[Override]
        public function flags(): array
        {
            return ['debug' => true];
        }
    };
    $nonScalar = new class implements SecurityInventoryContributor
    {
        #[Override]
        public function prefix(): string
        {
            return 'site_monitor';
        }

        #[Override]
        public function flags(): array
        {
            // Deliberately violates the contract so the merge rejects it.
            return ['targets' => ['https://example.test']]; // @phpstan-ignore return.type (this contributor deliberately breaks the scalar-only contract to prove the merge rejects it)
        }
    };
    $throwing = new class implements SecurityInventoryContributor
    {
        #[Override]
        public function prefix(): string
        {
            return 'login_audit';
        }

        #[Override]
        public function flags(): array
        {
            throw new RuntimeException('a-unique-contributor-secret');
        }
    };
    app()->instance('test.security.contributor.core_prefix', $corePrefix);
    app()->instance('test.security.contributor.non_scalar', $nonScalar);
    app()->instance('test.security.contributor.throwing', $throwing);
    app()->tag(['test.security.contributor.core_prefix', 'test.security.contributor.non_scalar', 'test.security.contributor.throwing'], SecurityInventoryContributor::TAG);

    $inventory = BuildSecurityPostureInventoryAction::run();
    $encoded = json_encode($inventory->toArray(), JSON_THROW_ON_ERROR);

    expect($inventory->flag('app.debug'))->toBe(config('app.debug') === true)
        ->and($inventory->flag('site_monitor.targets'))->toBeNull()
        ->and($inventory->flag('login_audit.anything'))->toBeNull()
        ->and($inventory->failures)->toBe([
            'inventory contributor prefix: ' . $corePrefix::class,
            'inventory contributor flags: ' . $nonScalar::class,
            'inventory contributor: ' . $throwing::class,
        ])
        ->and($encoded)->not->toContain('a-unique-contributor-secret');
});
