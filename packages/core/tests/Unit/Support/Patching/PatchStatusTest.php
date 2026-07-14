<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;

it('provides a translated label for every patch status', function (): void {
    expect(PatchStatus::Applicable->getLabel())->toBe(__('capell-core::patching.status_applicable'))
        ->and(PatchStatus::AlreadyApplied->getLabel())->toBe(__('capell-core::patching.status_already_applied'))
        ->and(PatchStatus::Customised->getLabel())->toBe(__('capell-core::patching.status_customised'))
        ->and(PatchStatus::Unsupported->getLabel())->toBe(__('capell-core::patching.status_unsupported'));
});

it('backs every patch status with a stable string value', function (): void {
    expect(PatchStatus::Applicable->value)->toBe('applicable')
        ->and(PatchStatus::AlreadyApplied->value)->toBe('already_applied')
        ->and(PatchStatus::Customised->value)->toBe('customised')
        ->and(PatchStatus::Unsupported->value)->toBe('unsupported');
});
