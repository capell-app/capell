<?php

declare(strict_types=1);

use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\PanelProviderWriter;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->originalBasePath = app()->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-provider-writer-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->temporaryBasePath);
    app()->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    app()->setBasePath($this->originalBasePath);
    File::deleteDirectory($this->temporaryBasePath);
});

it('keeps the identity of a concurrent installer save instead of overwriting it', function (): void {
    $path = panelProviderWriterFixture('admin');
    InstallRunState::recordScaffoldedPanelProviders([$path]);
    $original = File::get($path);
    $ours = str_replace('"admin"', '"ours"', $original);
    $theirs = str_replace('"admin"', '"theirs"', $original);

    // Pause our save while it updates the identity and let the other process save.
    $other = startPanelProviderProcessAtStateRead(2, 'save', $path, $theirs);
    resolve(PanelProviderWriter::class)->save($path, $original, $ours);
    $outcome = finishPanelProviderProcess($other);

    expect($outcome)->toBe('saved')
        ->and(File::get($path))->toBe($theirs)
        ->and(InstallRunState::authorisedPanelProvider())->toBe($path);
});

it('does not adopt a replacement made while a no-op save records its identity', function (): void {
    $path = panelProviderWriterFixture('admin');
    InstallRunState::recordScaffoldedPanelProviders([$path]);
    $original = File::get($path);
    $replacement = str_replace('"admin"', '"host"', $original);

    $other = startPanelProviderProcessAtStateRead(1, 'replace', $path, $replacement);
    resolve(PanelProviderWriter::class)->save($path, $original, $original);
    $outcome = finishPanelProviderProcess($other);

    expect($outcome)->toBe('replaced')
        ->and(File::get($path))->toBe($replacement)
        ->and(InstallRunState::authorisedPanelProvider())->toBeNull();
});

it('records the identity of the bytes it intended to write', function (): void {
    $path = panelProviderWriterFixture('admin');
    InstallRunState::recordScaffoldedPanelProviders([$path]);
    $original = File::get($path);
    $configured = str_replace('"admin"', '"configured"', $original);

    resolve(PanelProviderWriter::class)->save($path, $original, $configured);

    expect(InstallRunState::authorisedPanelProvider())->toBe($path);

    File::put($path, $original);

    expect(InstallRunState::authorisedPanelProvider())->toBeNull();
});

function panelProviderWriterFixture(string $id): string
{
    $path = app_path('Providers/Filament/AdminPanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<?php namespace App\\Providers\\Filament; class AdminPanelProvider { public function panel($panel) { return $panel->id("' . $id . '"); } }');

    return (string) realpath($path);
}

/**
 * Starts a second installer process when this process reads install state for
 * the given time, then waits until that process has either finished or blocked.
 */
function startPanelProviderProcessAtStateRead(int $read, string $mode, string $path, string $replacement): stdClass
{
    $scratch = base_path('process-' . bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($scratch);
    File::put($scratch . '/replacement.php', $replacement);
    $barrier = new stdClass;
    $barrier->process = null;

    $reads = 0;

    Event::listen(RetrievingKey::class, function (RetrievingKey $event) use (&$reads, $read, $mode, $path, $scratch, $barrier): void {
        if ($event->key !== 'capell.install.state' || $barrier->process instanceof Process || ++$reads !== $read) {
            return;
        }

        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/fixtures/panel-provider-writer-process.php',
            dirname(__DIR__, 5),
            base_path(),
            $mode,
            $path,
            $scratch . '/replacement.php',
            $scratch . '/ready',
        ]);
        $process->setTimeout(60);
        $process->start();

        $barrier->process = $process;

        $deadline = microtime(true) + 30;
        while (! is_file($scratch . '/ready') && $process->isRunning() && microtime(true) < $deadline) {
            Sleep::usleep(10_000);
        }

        // An unserialised writer completes within this window; a serialised one stays blocked.
        $deadline = microtime(true) + 2;
        while ($process->isRunning() && microtime(true) < $deadline) {
            Sleep::usleep(10_000);
        }
    });

    return $barrier;
}

function finishPanelProviderProcess(stdClass $barrier): string
{
    $process = $barrier->process;
    throw_unless($process instanceof Process, RuntimeException::class, 'The concurrent installer process never started.');
    $process->wait();
    throw_unless($process->isSuccessful(), RuntimeException::class, 'Concurrent installer process failed: ' . $process->getErrorOutput() . $process->getOutput());

    return trim($process->getOutput());
}
