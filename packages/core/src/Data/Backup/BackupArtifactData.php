<?php

declare(strict_types=1);

namespace Capell\Core\Data\Backup;

use Override;
use Spatie\LaravelData\Data;
use UnexpectedValueException;

final class BackupArtifactData extends Data
{
    public function __construct(
        public string $kind,
        public string $path,
        public int $bytes,
        public string $sha256,
        public ?string $sourceDisk = null,
        public ?string $sourcePath = null,
    ) {}

    /**
     * @param  array<string, mixed>  $artifact
     */
    public static function fromManifestArray(array $artifact): self
    {
        foreach (['kind', 'path', 'sha256'] as $key) {
            if (! is_string($artifact[$key] ?? null) || $artifact[$key] === '') {
                throw new UnexpectedValueException(sprintf('Backup artifact [%s] is invalid.', $key));
            }
        }

        if (! is_int($artifact['bytes'] ?? null) || $artifact['bytes'] < 0) {
            throw new UnexpectedValueException('Backup artifact [bytes] is invalid.');
        }

        if (preg_match('/\A[a-f0-9]{64}\z/', $artifact['sha256']) !== 1) {
            throw new UnexpectedValueException('Backup artifact [sha256] is invalid.');
        }

        foreach (['source_disk', 'source_path'] as $key) {
            if (isset($artifact[$key]) && ! is_string($artifact[$key])) {
                throw new UnexpectedValueException(sprintf('Backup artifact [%s] is invalid.', $key));
            }
        }

        return new self(
            kind: $artifact['kind'],
            path: $artifact['path'],
            bytes: $artifact['bytes'],
            sha256: $artifact['sha256'],
            sourceDisk: $artifact['source_disk'] ?? null,
            sourcePath: $artifact['source_path'] ?? null,
        );
    }

    /**
     * @return array{kind: string, path: string, bytes: int, sha256: string, source_disk: string|null, source_path: string|null}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'path' => $this->path,
            'bytes' => $this->bytes,
            'sha256' => $this->sha256,
            'source_disk' => $this->sourceDisk,
            'source_path' => $this->sourcePath,
        ];
    }
}
