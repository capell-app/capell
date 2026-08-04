<?php

declare(strict_types=1);

use Capell\Core\Support\Diagnostics\Checks\RuntimeToolingCheck;
use Capell\Core\Support\Process\ProcessExecutionSupport;

it('reports process execution availability for the running PHP process', function (): void {
    expect(ProcessExecutionSupport::isAvailable())->toBe(
        function_exists('proc_open') && ! ProcessExecutionSupport::isDisabled('proc_open'),
    );
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
