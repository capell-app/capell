<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\Install\ArtisanCommandResultData;
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Process\ArtisanProcessEnvironment;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

final class CallArtisanCommandAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly ProcessFactoryInterface $processFactory,
        private readonly RuntimeBinaryResolver $binaryResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @param  (callable(string, string): void)|null  $onOutput
     */
    public function handle(
        string $command,
        array $arguments = [],
        bool $freshProcess = false,
        ?float $timeout = 120,
        bool $captureOutput = true,
        ?callable $onOutput = null,
    ): ArtisanCommandResultData {
        if (! $freshProcess && array_key_exists($command, Artisan::all())) {
            $output = new BufferedOutput;
            $exitCode = Artisan::call($command, $arguments, $output);

            return new ArtisanCommandResultData($exitCode, $captureOutput ? $output->fetch() : '');
        }

        // Composer can add providers after the running console application has
        // collected its commands. A child must boot the project's updated app.
        $prefix = [...$this->binaryResolver->php(), base_path('artisan')];
        $environment = ArtisanProcessEnvironment::prepare(ComposerProcessEnvironment::forInstall($_SERVER));
        $probe = $this->processFactory->make(
            [...$prefix, 'list', '--format=json', '--no-interaction'],
            base_path(),
            $environment,
        );
        $probe->setTimeout($timeout);
        $probe->run();

        $commandList = $probe->isSuccessful() ? $probe->getOutput() : '';

        if ($this->commandIsMissing($command, $commandList)) {
            throw new RuntimeException(__('capell-core::install.command.not_found', ['command' => $command]));
        }

        // A failed or malformed probe is a boot failure, not proof that the
        // command is missing. Run it so its actual output and status survive.
        $process = $this->processFactory->make(
            [...$prefix, $command, ...$this->commandArguments($command, $arguments, $commandList)],
            base_path(),
            $environment,
        );
        $process->setTimeout($timeout);

        if (! $captureOutput) {
            $process->disableOutput();
        }

        $process->run($onOutput);

        return new ArtisanCommandResultData(
            $process->getExitCode() ?? 1,
            $captureOutput ? $process->getOutput() : '',
            $captureOutput ? $process->getErrorOutput() : '',
        );
    }

    private function commandIsMissing(string $command, string $output): bool
    {
        $decoded = json_decode($output, true);

        if (! is_array($decoded) || ! is_array($decoded['commands'] ?? null)) {
            return false;
        }

        // The JSON descriptor includes hidden commands. Namespace command
        // lists also include aliases, which are valid Artisan command names.
        $names = [];

        foreach ($decoded['commands'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null)) {
                return false;
            }

            $names[] = $entry['name'];
        }

        $namespaces = $decoded['namespaces'] ?? [];

        if (! is_array($namespaces)) {
            return false;
        }

        foreach ($namespaces as $namespace) {
            if (! is_array($namespace) || ! is_array($namespace['commands'] ?? null)) {
                return false;
            }

            $names = [...$names, ...$namespace['commands']];
        }

        return ! in_array($command, $names, true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     */
    private function commandArguments(string $command, array $arguments, string $commandList): array
    {
        $tokens = [];
        $positionals = [];

        foreach ($arguments as $name => $value) {
            $isOption = str_starts_with($name, '-');
            if ($value === null) {
                continue;
            }

            if ($isOption && $value === false) {
                continue;
            }

            if (! $isOption) {
                $positionals[$name] = $value;

                continue;
            }

            if ($value === true) {
                $tokens[] = $name;

                continue;
            }

            foreach (is_array($value) ? $value : [$value] as $item) {
                $tokens[] = $name . '=' . $item;
            }
        }

        if (! array_key_exists('--no-interaction', $arguments) && ! array_key_exists('-n', $arguments)) {
            $tokens[] = '--no-interaction';
        }

        $decoded = json_decode($commandList, true);

        if (is_array($decoded) && is_array($decoded['commands'] ?? null)) {
            foreach ($decoded['commands'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $aliases = is_array($entry['usage'] ?? null) ? $entry['usage'] : [];

                if (($entry['name'] ?? null) !== $command && ! in_array($command, $aliases, true)) {
                    continue;
                }

                if (is_array($entry['definition']['arguments'] ?? null)) {
                    $positionals = array_replace(array_intersect_key($entry['definition']['arguments'], $positionals), $positionals);
                }

                break;
            }
        }

        if ($positionals !== []) {
            // Named ArrayInput arguments bind by signature order. Keep values
            // starting with a dash as data rather than interpreting options.
            $tokens[] = '--';

            foreach ($positionals as $value) {
                foreach (is_array($value) ? $value : [$value] as $item) {
                    $tokens[] = (string) $item;
                }
            }
        }

        return $tokens;
    }
}
