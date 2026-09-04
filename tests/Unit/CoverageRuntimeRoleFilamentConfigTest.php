<?php

declare(strict_types=1);

it('keeps package-merged Filament config after the runtime-role bootstrap double-boots the app', function (): void {
    if (getenv('CAPELL_TESTBENCH_RUNTIME_ROLE') !== 'true') {
        $this->markTestSkipped('Only reproducible under the Coverage CI job\'s CAPELL_TESTBENCH_RUNTIME_ROLE=true bootstrap.');
    }

    // CAP-0501 regression: Testbench's LoadConfiguration bootstrap rebuilds the
    // config repository from an empty array on the runtime-role double-boot pass
    // and never re-runs already-registered providers' mergeConfigFrom(), so
    // filament.default_filesystem_disk silently reverted to null and
    // Filament\Tables\Columns\ImageColumn::getDiskName() threw a TypeError.
    expect(config('filament.default_filesystem_disk'))->toBeString()->not->toBe('');
});
