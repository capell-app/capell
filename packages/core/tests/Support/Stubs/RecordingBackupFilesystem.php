<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Stubs;

use Illuminate\Filesystem\FilesystemAdapter;
use Override;
use RuntimeException;

final class RecordingBackupFilesystem extends FilesystemAdapter
{
    /** @var list<string> */
    public array $temporaryPaths = [];

    public int $peakMediaBytes = 0;

    public int $writes = 0;

    public int $reads = 0;

    public ?int $failWriteAt = null;

    public ?int $failReadAt = null;

    public ?int $corruptReadAt = null;

    public function __construct(FilesystemAdapter $disk)
    {
        parent::__construct($disk->getDriver(), $disk->getAdapter(), $disk->getConfig());
    }

    #[Override]
    public function put(mixed $path, mixed $contents, mixed $options = []): bool
    {
        $this->writes++;

        if (is_resource($contents)) {
            $uri = stream_get_meta_data($contents)['uri'] ?? null;

            if (is_string($uri)) {
                $this->temporaryPaths[] = $uri;
                $bytes = 0;

                foreach ($this->temporaryPaths as $temporaryPath) {
                    if (is_file($temporaryPath) && preg_match('/capell-(restore-)?media-/', basename($temporaryPath)) === 1) {
                        $bytes += filesize($temporaryPath);
                    }
                }

                $this->peakMediaBytes = max($this->peakMediaBytes, $bytes);
            }
        }

        return $this->writes !== $this->failWriteAt && parent::put($path, $contents, $options);
    }

    /** @return resource|null */
    #[Override]
    public function readStream(mixed $path): mixed
    {
        $this->reads++;

        if ($this->reads === $this->failReadAt) {
            return null;
        }

        $stream = parent::readStream($path);

        if ($this->reads !== $this->corruptReadAt || ! is_resource($stream)) {
            return $stream;
        }

        fclose($stream);
        $stream = fopen('php://temp', 'w+b');
        throw_unless(is_resource($stream), RuntimeException::class, 'Unable to create a corrupted backup stream.');
        fwrite($stream, 'corrupted download');
        rewind($stream);

        return $stream;
    }
}
