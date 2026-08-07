<?php

declare(strict_types=1);

use Capell\Core\Support\Diagnostics\Checks\RuntimeToolingCheck;
use Capell\Core\Support\Process\ProcessExecutionSupport;

it('refuses process execution when proc_open is listed in disable_functions', function (): void {
    // The shared-host case: proc_open is a defined symbol, so function_exists
    // alone reports a false pass. Only the disable_functions list catches it.
    expect(function_exists('proc_open'))->toBeTrue()
        ->and(ProcessExecutionSupport::isAvailableWith('exec, PROC_OPEN ,passthru'))->toBeFalse();
});

it('allows process execution when disable_functions does not name proc_open', function (): void {
    expect(ProcessExecutionSupport::isAvailableWith('exec,passthru'))->toBeTrue()
        ->and(ProcessExecutionSupport::isAvailableWith(''))->toBeTrue();
});

it('detects a function listed in disable_functions regardless of spacing and case', function (): void {
    expect(ProcessExecutionSupport::disabledFunctionsFrom('exec, PROC_OPEN ,passthru'))
        ->toBe(['exec', 'proc_open', 'passthru']);
});

it('treats an empty disable_functions list as disabling nothing', function (): void {
    expect(ProcessExecutionSupport::disabledFunctionsFrom(''))->toBe([]);
});

it('matches the availability the runtime tooling doctor check reports', function (): void {
    // RuntimeToolingCheck used to carry its own copy of this probe. The extracted
    // helper must agree with it, or the doctor and the installer disagree about
    // the same host.
    $result = new RuntimeToolingCheck()->check();

    expect($result->evidence['proc_open'])->toBe(ProcessExecutionSupport::isAvailable());
});
