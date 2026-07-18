<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(list<string> $classes)
 */
final class UpdateTailwindClassesFileAction
{
    use AsFake;
    use AsObject;

    protected string $filePath;

    protected string $filename = 'tailwind-classes.txt';

    public function __construct(?string $filePath = null)
    {
        $dir = $filePath ?? storage_path('capell');

        File::ensureDirectoryExists($dir);

        $this->filePath = rtrim($dir, '/') . '/' . $this->filename;
    }

    /**
     * @param  list<string>  $classes
     */
    public function handle(array $classes): void
    {
        $existing = [];

        if (File::exists($this->filePath)) {
            $existing = array_filter(
                explode("\n", File::get($this->filePath)),
                static fn (string $line): bool => $line !== '',
            );
        }

        $all = array_unique(array_merge($existing, $classes));

        sort($all);

        File::put($this->filePath, implode("\n", $all));
    }
}
