<?php

declare(strict_types=1);

namespace Capell\Core\Data\Backup;

use DateTimeImmutable;
use Override;
use Spatie\LaravelData\Data;
use UnexpectedValueException;

final class BackupManifestData extends Data
{
    /**
     * @param  list<BackupArtifactData>  $media
     */
    public function __construct(
        public int $formatVersion,
        public string $snapshotId,
        public string $createdAt,
        public string $databaseDriver,
        public string $connectionName,
        public BackupArtifactData $database,
        public array $media,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public static function fromManifestArray(array $manifest): self
    {
        foreach (['snapshot_id', 'created_at', 'database_driver', 'connection_name'] as $key) {
            if (! is_string($manifest[$key] ?? null) || $manifest[$key] === '') {
                throw new UnexpectedValueException(sprintf('Backup manifest [%s] is invalid.', $key));
            }
        }

        throw_if(($manifest['format_version'] ?? null) !== 1, UnexpectedValueException::class, 'Backup manifest format version is unsupported.');

        throw_if(! is_array($manifest['database'] ?? null) || ! is_array($manifest['media'] ?? null), UnexpectedValueException::class, 'Backup manifest artifacts are invalid.');

        throw_if(preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{12}\z/', $manifest['snapshot_id']) !== 1
            || ! in_array($manifest['database_driver'], ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)
            || preg_match('/\A[A-Za-z0-9_.-]+\z/', $manifest['connection_name']) !== 1, UnexpectedValueException::class, 'Backup manifest identity is invalid.');

        $createdAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $manifest['created_at']);

        throw_if($createdAt === false || $createdAt->format(DATE_ATOM) !== $manifest['created_at'], UnexpectedValueException::class, 'Backup manifest timestamp is invalid.');

        $database = BackupArtifactData::fromManifestArray($manifest['database']);

        throw_if($database->kind !== 'database', UnexpectedValueException::class, 'Backup manifest database artifact is invalid.');

        $media = [];

        foreach ($manifest['media'] as $artifact) {
            throw_unless(is_array($artifact), UnexpectedValueException::class, 'Backup manifest media artifact is invalid.');

            $mediaArtifact = BackupArtifactData::fromManifestArray($artifact);

            throw_if($mediaArtifact->kind !== 'media' || $mediaArtifact->sourceDisk === null || $mediaArtifact->sourcePath === null, UnexpectedValueException::class, 'Backup manifest media artifact is invalid.');

            $media[] = $mediaArtifact;
        }

        $mediaBytes = array_sum(array_map(static fn (BackupArtifactData $artifact): int => $artifact->bytes, $media));

        throw_if(($manifest['media_file_count'] ?? null) !== count($media) || ($manifest['media_bytes'] ?? null) !== $mediaBytes, UnexpectedValueException::class, 'Backup manifest media totals are invalid.');

        return new self(
            formatVersion: 1,
            snapshotId: $manifest['snapshot_id'],
            createdAt: $manifest['created_at'],
            databaseDriver: $manifest['database_driver'],
            connectionName: $manifest['connection_name'],
            database: $database,
            media: $media,
        );
    }

    /**
     * @return array{
     *     format_version: int,
     *     snapshot_id: string,
     *     created_at: string,
     *     database_driver: string,
     *     connection_name: string,
     *     database: array{kind: string, path: string, bytes: int, sha256: string, source_disk: string|null, source_path: string|null},
     *     media: list<array{kind: string, path: string, bytes: int, sha256: string, source_disk: string|null, source_path: string|null}>,
     *     media_file_count: int,
     *     media_bytes: int
     * }
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'format_version' => $this->formatVersion,
            'snapshot_id' => $this->snapshotId,
            'created_at' => $this->createdAt,
            'database_driver' => $this->databaseDriver,
            'connection_name' => $this->connectionName,
            'database' => $this->database->toArray(),
            'media' => array_map(
                static fn (BackupArtifactData $artifact): array => $artifact->toArray(),
                $this->media,
            ),
            'media_file_count' => count($this->media),
            'media_bytes' => array_sum(array_map(
                static fn (BackupArtifactData $artifact): int => $artifact->bytes,
                $this->media,
            )),
        ];
    }
}
