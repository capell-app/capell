<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Illuminate\Filesystem\Filesystem;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class IntegrateViteInputsAction
{
    use AsFake;
    use AsObject;

    private const string IMPORT = "import { capellViteInputs } from './vendor/capell-app/frontend/resources/js/capell-vite-inputs.js';";

    public function __construct(private readonly Filesystem $files) {}

    public function handle(): string
    {
        $path = collect(['vite.config.js', 'vite.config.mjs', 'vite.config.ts'])
            ->map(fn (string $candidate): string => base_path($candidate))
            ->first(fn (string $candidate): bool => $this->files->isFile($candidate));

        throw_unless(is_string($path), RuntimeException::class, 'No supported Vite configuration file was found.');

        $contents = $this->files->get($path);

        if (str_contains($contents, 'capellViteInputs') && str_contains($contents, 'capell-vite-inputs.js')) {
            return $path;
        }

        throw_unless(
            preg_match('/\binput\s*:\s*\[[^\]]*[\'"]resources\/(?:css|js)\//s', $contents) === 1,
            RuntimeException::class,
            'The Vite input array is customised and could not be updated automatically.',
        );

        $updated = self::IMPORT . PHP_EOL . $contents;
        $updated = preg_replace(
            '/(\binput\s*:\s*\[\s*\n)([ \t]*)/',
            '$1$2...capellViteInputs(),' . PHP_EOL . '$2',
            $updated,
            1,
            $multilineCount,
        );
        throw_unless(is_string($updated), RuntimeException::class, 'The Vite configuration could not be updated automatically.');

        if ($multilineCount === 0) {
            $updated = preg_replace(
                '/(\binput\s*:\s*\[)/',
                '$1...capellViteInputs(), ',
                $updated,
                1,
                $inlineCount,
            );

            throw_unless($inlineCount === 1, RuntimeException::class, 'The Vite input array could not be updated automatically.');
        }

        $this->files->put($path, $updated);

        return $path;
    }
}
