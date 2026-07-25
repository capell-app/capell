<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Support\Json\JsonCodec;
use Capell\Frontend\Support\Assets\FrontendViteInputRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class WriteViteInputManifestAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly Filesystem $files,
        private readonly FrontendViteInputRegistry $registry,
    ) {}

    /**
     * @param  array<int, array{path: string, content: string}>  $generatedAssets
     * @return list<string>
     */
    public function handle(array $generatedAssets): array
    {
        $inputs = collect($generatedAssets)
            ->map(fn (array $asset): string => $this->relativeInputPath($asset['path']))
            ->merge($this->registry->all())
            ->unique()
            ->sort()
            ->filter()
            ->values()
            ->all();
        $path = base_path('bootstrap/cache/capell-vite-inputs.json');

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put(
            $path,
            JsonCodec::encode(['inputs' => $inputs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        );

        return array_values($inputs);
    }

    private function relativeInputPath(string $path): string
    {
        $base = Str::finish(base_path(), DIRECTORY_SEPARATOR);

        return Str::startsWith($path, $base)
            ? str_replace(DIRECTORY_SEPARATOR, '/', Str::after($path, $base))
            : str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
