<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Install\InstallPatchContext;
use Capell\Core\Support\Patching\Patch;

final class InstallPatchReceiptCapturedState
{
    public function __construct(
        public string $name,
        public InstallPatchReceiptCapturedMode $mode,
        public mixed $nested = null,
    ) {}

    public function factory(): callable
    {
        return fn (InstallPatchContext $context): Patch => makeInstallPatchRegistryTestPatch($this->name);
    }

    public function unusedFactory(): callable
    {
        return function (InstallPatchContext $context): Patch {
            $literal = '$this';
            $thisValue = 'not-bound-state';
            expect($literal)->toBe('$this')
                ->and($thisValue)->toBe('not-bound-state');

            return makeInstallPatchRegistryTestPatch('unused-bound');
        };
    }

    public function literalFactory(): callable
    {
        return function (InstallPatchContext $context): Patch {
            $literal = '$this';
            $thisValue = 'not-bound-state';
            expect($literal)->toBe('$this')
                ->and($thisValue)->toBe('not-bound-state');

            return makeInstallPatchRegistryTestPatch('literal-bound');
        };
    }
}
