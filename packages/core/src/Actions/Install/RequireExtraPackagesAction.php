<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Composer\ComposerAutoloaderReloader;
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

class RequireExtraPackagesAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly ProcessFactoryInterface $processFactory,
    ) {}

    /**
     * @param  array<string>  $packages  Composer package names (e.g. "vendor/name").
     */
    public function handle(array $packages, ProgressReporter $reporter): void
    {
        if ($packages === []) {
            return;
        }

        $reporter->step('Requiring extra packages via Composer…');

        $packageArgs = app()->isLocal()
            ? array_map(fn (string $name): string => str_contains($name, ':') ? $name : $name . ':*', $packages)
            : $packages;

        /** @var list<string> $command */
        $command = array_merge(['composer', 'require', '--no-interaction', '--prefer-dist', '--with-all-dependencies'], $packageArgs);

        $env = ComposerProcessEnvironment::forInstall($_SERVER);

        $process = $this->processFactory->make($command, base_path(), $env);
        $process->setTimeout(600);
        $process->disableOutput();

        $outputTail = '';

        $process->run(function (string $type, string $buffer) use ($reporter, &$outputTail): void {
            $outputTail = substr($outputTail . $buffer, -65_536);

            foreach (explode("\n", trim($buffer)) as $line) {
                if ($line !== '') {
                    $reporter->report($line);
                }
            }
        });

        if (! $process->isSuccessful()) {
            $error = trim($outputTail);
            throw new RuntimeException(
                sprintf('Failed to require extra packages [%s]: %s', implode(', ', $packages), $error !== '' ? $error : 'Unknown error.'),
            );
        }

        ComposerAutoloaderReloader::reload();

        CapellCore::clearExtensionCache();

        $reporter->report(sprintf('✓ Required: %s', implode(', ', $packages)));
    }
}
