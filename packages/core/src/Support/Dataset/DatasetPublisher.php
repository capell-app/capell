<?php

declare(strict_types=1);

namespace Capell\Core\Support\Dataset;

use Exception;
use Illuminate\Support\Facades\File;
use RuntimeException;

class DatasetPublisher
{
    public function validateType(string $type): bool
    {
        return in_array($type, ['migrations', 'settings'], true);
    }

    public function normalizePath(?string $path): ?string
    {
        if (in_array($path, [null, '', '0'], true)) {
            return null;
        }

        $real = realpath($path);
        if ($real === false) {
            return null;
        }

        return rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function publish(string $type, array $data): void
    {
        $path = database_path($type . '/sitemap.php');
        try {
            File::put($path, '<?php return ' . var_export($data, true) . ';');
        } catch (Exception $exception) {
            throw new RuntimeException('Failed to write dataset: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
