<?php

declare(strict_types=1);

namespace Capell\Core\Data\Privacy;

/**
 * One package's part of a privacy export: its data about the person as a
 * JSON-serialisable array, and the stored files (`disk:path`) the export
 * archive should include.
 */
final readonly class PrivacyExportSectionData
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $files
     */
    public function __construct(
        public array $data = [],
        public array $files = [],
    ) {
        PrivacyFileReference::assertAllValid($files);
    }

    public function isEmpty(): bool
    {
        return $this->data === [] && $this->files === [];
    }
}
