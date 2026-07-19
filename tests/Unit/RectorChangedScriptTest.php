<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->rectorScriptTestRoot = sys_get_temp_dir() . '/capell-rector-changed-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->rectorScriptTestRoot . '/vendor/bin');

    File::put($this->rectorScriptTestRoot . '/example.php', "<?php\n\nreturn 'before';\n");
    File::put(
        $this->rectorScriptTestRoot . '/vendor/bin/rector',
        "<?php\n\necho implode('|', array_slice(\$argv, 1));\n",
    );

    rectorChangedTestProcess(['git', 'init'], $this->rectorScriptTestRoot)->mustRun();
    rectorChangedTestProcess(['git', 'config', 'user.email', 'rector-test@capell.test'], $this->rectorScriptTestRoot)->mustRun();
    rectorChangedTestProcess(['git', 'config', 'user.name', 'Capell Rector Test'], $this->rectorScriptTestRoot)->mustRun();
    rectorChangedTestProcess(['git', 'add', '.'], $this->rectorScriptTestRoot)->mustRun();
    rectorChangedTestProcess(['git', 'commit', '-m', 'baseline'], $this->rectorScriptTestRoot)->mustRun();

    File::put($this->rectorScriptTestRoot . '/example.php', "<?php\n\nreturn 'after';\n");
});

afterEach(function (): void {
    File::deleteDirectory($this->rectorScriptTestRoot);
});

it('runs Rector for changed PHP files without optional arguments', function (): void {
    $process = rectorChangedTestProcess([
        'bash',
        dirname(__DIR__, 2) . '/scripts/rector-changed.sh',
    ], $this->rectorScriptTestRoot);

    $process->mustRun();

    expect($process->getOutput())
        ->toContain('Running Rector on 1 changed PHP file(s)...')
        ->toContain('--no-progress-bar|example.php');
});

it('forwards optional Rector arguments', function (): void {
    $process = rectorChangedTestProcess([
        'bash',
        dirname(__DIR__, 2) . '/scripts/rector-changed.sh',
        '--dry-run',
    ], $this->rectorScriptTestRoot);

    $process->mustRun();

    expect($process->getOutput())->toContain('--no-progress-bar|--dry-run|example.php');
});

/**
 * @param  list<string>  $command
 */
function rectorChangedTestProcess(array $command, string $workingDirectory): Process
{
    return new Process(
        $command,
        $workingDirectory,
        ['PHP_BINARY' => PHP_BINARY],
    );
}
