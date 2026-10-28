<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Illuminate\Filesystem\Filesystem;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class FindPublishedViewDriftAction
{
    use AsFake;
    use AsObject;

    /**
     * Differences require review; a customised override is not necessarily stale.
     * Missing upstream files cannot be compared and must also be reviewed.
     *
     * @return array{different: list<string>, missing: list<string>}
     */
    public function handle(string $publishedDirectory, string $packageDirectory): array
    {
        $different = [];
        $missing = [];
        $filesystem = new Filesystem;

        if (! $filesystem->isDirectory($publishedDirectory)) {
            return ['different' => [], 'missing' => []];
        }

        foreach ($filesystem->allFiles($publishedDirectory) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $upstream = $packageDirectory . '/' . $relative;
            if (! $filesystem->isFile($upstream) || ! $filesystem->isReadable($upstream) || ! $filesystem->isReadable($file->getPathname())) {
                $missing[] = $relative;

                continue;
            }

            if ($filesystem->hash($file->getPathname(), 'sha256') !== $filesystem->hash($upstream, 'sha256')) {
                $different[] = $relative;
            }
        }

        sort($different);
        sort($missing);

        return ['different' => $different, 'missing' => $missing];
    }
}
