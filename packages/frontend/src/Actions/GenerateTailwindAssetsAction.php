<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Illuminate\Filesystem\Filesystem;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

class GenerateTailwindAssetsAction
{
    use AsFake;
    use AsObject;

    /**
     * Generate per-theme Tailwind asset files and return their absolute paths with contents.
     *
     * Resolves TailwindAssetsGenerator from the container so tests and host apps can
     * swap the frontend-owned generator without changing the action contract.
     *
     * @return array<int, array{path: string, content: string}>
     */
    public function handle(?string $outputPath = null): array
    {
        $files = resolve(Filesystem::class);

        /** @var object $generator */
        $generator = resolve('capell.tailwind.generator');
        $callback = [$generator, 'generate'];

        throw_unless(is_callable($callback), RuntimeException::class, 'Tailwind asset generator is not callable.');

        $paths = $callback($outputPath);

        throw_unless(is_array($paths), RuntimeException::class, 'Tailwind asset generator must return paths.');

        return collect($paths)
            ->map(fn (string $path): array => ['path' => $path, 'content' => $files->get($path)])
            ->values()
            ->all();
    }
}
