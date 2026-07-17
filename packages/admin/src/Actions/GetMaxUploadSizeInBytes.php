<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run()
 */
class GetMaxUploadSizeInBytes
{
    use AsFake;
    use AsObject;

    public function handle(): int
    {
        $maxUploadMb = Config::get('files.max_upload_mb');
        if (is_numeric($maxUploadMb) && $maxUploadMb > 0) {
            return (int) $maxUploadMb * 1024 * 1024;
        }

        $size = ini_get('upload_max_filesize');

        return $this->sizeToBytes($size);
    }

    private function sizeToBytes(string|bool $size_str): int
    {
        $size_str = (string) $size_str;

        return match (mb_substr($size_str, -1)) {
            'M', 'm' => (int) $size_str * 1048576,
            'K', 'k' => (int) $size_str * 1024,
            'G', 'g' => (int) $size_str * 1073741824,
            default => (int) $size_str,
        };
    }
}
