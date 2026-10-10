<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\CallArtisanCommandAction;
use Capell\Core\Data\Install\ArtisanCommandResultData;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
use Capell\Core\Support\Process\SymfonyProcessFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    config([RuntimeBinaryResolver::PHP_CONFIG_KEY => PHP_BINARY]);
});

/** The external application's commands run for real, without its shared filesystem. */
function withFreshArtisanFixture(Closure $assert, int $exitCode = 0, string $mode = 'normal'): void
{
    $original = base_path();
    $root = dirname(__DIR__, 6);
    $directory = sys_get_temp_dir() . '/capell-fresh-artisan-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory);
    $bootstrap = '<?php require ' . var_export($root . '/vendor/autoload.php', true) . ';'
        . '$fixtureExitCode = ' . $exitCode . '; $fixtureMode = ' . var_export($mode, true) . ';';
    File::put($directory . '/artisan', $bootstrap . <<<'PHP_WRAP'
    if (in_array($fixtureMode, ['failed-probe', 'malformed-probe', 'invalid-probe'], true)) {
        if (($argv[1] ?? '') === 'list') {
            echo match ($fixtureMode) {
                'failed-probe' => 'Boot failed.',
                'malformed-probe' => 'Unexpected bootstrap output.',
                'invalid-probe' => '{"commands":[{}]}',
            };
            exit($fixtureMode === 'failed-probe' ? 1 : 0);
        }
        fwrite(STDERR, 'Application boot failed.');
        exit(19);
    }
    $app = new Symfony\Component\Console\Application;
    $app->setAutoExit(false);
    $command = new Symfony\Component\Console\Command\Command('test:late-install');
    $command->setAliases(['test:alias'])->setHidden(true);
    $command->addArgument('first', Symfony\Component\Console\Input\InputArgument::OPTIONAL);
    $command->addArgument('second', Symfony\Component\Console\Input\InputArgument::OPTIONAL);
    $command->addOption('count', null, Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED);
    $command->addOption('force', null, Symfony\Component\Console\Input\InputOption::VALUE_NONE);
    $command->addOption('languages', null, Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED | Symfony\Component\Console\Input\InputOption::VALUE_IS_ARRAY);
    $command->setCode(function (Symfony\Component\Console\Input\InputInterface $input, Symfony\Component\Console\Output\OutputInterface $output) use ($fixtureExitCode, $fixtureMode): int {
        if ($fixtureMode === 'stream') {
            $output->write('Demo progress.');
            return 0;
        }
        $output->write(json_encode([
            'arguments' => $input->getArguments(),
            'options' => $input->getOptions(),
            'cache' => getenv('APP_CONFIG_CACHE'),
            'cwd' => getcwd(),
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        ], JSON_THROW_ON_ERROR));
        fwrite(STDERR, 'Fresh stderr.');
        return $fixtureExitCode;
    });
    $app->addCommand($command);
    exit($app->run());
    PHP_WRAP);
    app()->setBasePath($directory);
    try {
        $assert(new CallArtisanCommandAction(new SymfonyProcessFactory, new RuntimeBinaryResolver), $directory);
    } finally {
        app()->setBasePath($original);
        File::deleteDirectory($directory);
    }
}

it('runs a registered command and captures its arguments output and exit code', function (): void {
    Artisan::command('test:registered-install {name} {--count=} {--force}', function (): int {
        $this->line(json_encode([
            'name' => $this->argument('name'), 'count' => $this->option('count'), 'force' => $this->option('force'),
        ], JSON_THROW_ON_ERROR));

        return 17;
    });
    $result = CallArtisanCommandAction::run('test:registered-install', ['name' => 'A site with spaces', '--count' => 7, '--force' => true]);
    expect($result->exitCode)->toBe(17)
        ->and(json_decode((string) $result->output, true, flags: JSON_THROW_ON_ERROR))->toBe(['name' => 'A site with spaces', 'count' => 7, 'force' => true])
        ->and($result->errorOutput)->toBe('');
});

it('falls back to the fresh application with equivalent CLI arguments output and exit status', function (): void {
    withFreshArtisanFixture(function (CallArtisanCommandAction $action, string $directory): void {
        $streamed = [];
        $result = $action->handle('test:late-install', [
            'first' => 'A site with spaces', '--count' => 7, '--force' => true,
            '--languages' => ['en', 'fr'], '--sites' => null, '--ansi' => false,
        ], onOutput: function (string $type, string $buffer) use (&$streamed): void {
            $streamed[$type] = ($streamed[$type] ?? '') . $buffer;
        });
        $payload = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        expect($result->exitCode)->toBe(23)
            ->and($payload['arguments']['first'])->toBe('A site with spaces')
            ->and($payload['options']['count'])->toBe('7')
            ->and($payload['options']['force'])->toBeTrue()
            ->and($payload['options']['languages'])->toBe(['en', 'fr'])
            ->and($payload['options']['no-interaction'])->toBeTrue()
            ->and($payload['cwd'])->toBe(realpath($directory))
            ->and($payload['php'])->toBe('8.4')
            ->and($result->errorOutput)->toBe('Fresh stderr.')
            ->and($streamed[Process::OUT])->toBe($result->output)
            ->and($streamed[Process::ERR])->toBe($result->errorOutput)
            ->and($result->combinedOutput())->toBe($result->output . "\nFresh stderr.");
    }, exitCode: 23);
});

it('reports a translated error for a command missing from both applications', function (): void {
    withFreshArtisanFixture(function (CallArtisanCommandAction $action): void {
        expect(fn (): ArtisanCommandResultData => $action->handle('test:missing-install'))
            ->toThrow(RuntimeException::class, __('capell-core::install.command.not_found', ['command' => 'test:missing-install']));
    });
});

it('preserves signature order and dash-prefixed positional values', function (): void {
    withFreshArtisanFixture(function (CallArtisanCommandAction $action): void {
        $result = $action->handle('test:late-install', ['second' => 'second value', 'first' => '--a-value']);
        expect($result->exitCode)->toBe(0);
        $payload = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        expect($payload['arguments']['first'])->toBe('--a-value')
            ->and($payload['arguments']['second'])->toBe('second value');
    });
});

it('runs hidden commands and aliases from the fresh application', function (string $command): void {
    withFreshArtisanFixture(function (CallArtisanCommandAction $action) use ($command): void {
        $result = $action->handle($command, ['first' => 'Public argument']);
        expect($result->exitCode)->toBe(0)
            ->and(json_decode($result->output, true, flags: JSON_THROW_ON_ERROR)['arguments']['first'])->toBe('Public argument');
    });
})->with(['hidden' => 'test:late-install', 'alias' => 'test:alias']);

it('preserves boot failure output when command discovery fails', function (string $mode): void {
    withFreshArtisanFixture(function (CallArtisanCommandAction $action): void {
        $result = $action->handle('test:late-install');
        expect($result->exitCode)->toBe(19)
            ->and($result->combinedOutput())->toBe('Application boot failed.');
    }, mode: $mode);
})->with(['failed-probe', 'malformed-probe', 'invalid-probe']);

it('preserves failures raised inside a registered command', function (Throwable $failure): void {
    Artisan::command('test:registered-failure', function () use ($failure): never {
        throw $failure;
    });
    expect(fn (): mixed => CallArtisanCommandAction::run('test:registered-failure'))
        ->toThrow($failure::class, $failure->getMessage());
})->with([new RuntimeException('A real install failure.'), new CommandNotFoundException('A nested command is missing.')]);

it('honours a fresh boot request even for a command registered in the calling application', function (): void {
    Artisan::command('test:late-install', function (): never {
        throw new RuntimeException('The stale application must not run this command.');
    });
    withFreshArtisanFixture(function (CallArtisanCommandAction $action): void {
        expect($action->handle('test:late-install', freshProcess: true)->exitCode)->toBe(0);
    });
});

it('streams output without retaining a second copy when capture is disabled', function (): void {
    withFreshArtisanFixture(function (CallArtisanCommandAction $action): void {
        $streamed = '';
        $result = $action->handle('test:late-install', captureOutput: false, onOutput: function (string $type, string $buffer) use (&$streamed): void {
            $streamed .= $buffer;
        });
        expect($streamed)->toBe('Demo progress.')
            ->and($result->exitCode)->toBe(0)
            ->and($result->combinedOutput())->toBe('');
    }, mode: 'stream');
});

it('captures parent command output when it dispatches another command', function (): void {
    Artisan::command('test:nested-install-child', function (): int {
        $this->line('Child output.');

        return 0;
    });
    Artisan::command('test:nested-install-parent', function (): int {
        Artisan::call('test:nested-install-child');
        $this->line('Parent output.');

        return 0;
    });
    expect(CallArtisanCommandAction::run('test:nested-install-parent')->combinedOutput())->toBe('Parent output.');
});
