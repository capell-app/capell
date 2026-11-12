<?php

declare(strict_types=1);

namespace Capell\Core\Data\Privacy;

use Capell\Core\Enums\PrivacyErasureMode;
use InvalidArgumentException;

/**
 * What one package erased for a privacy request: how many records it deleted
 * or anonymised, which stored files it removed (as `disk:path`, never their
 * contents), and whether it kept a suppression marker. Kept with the request as
 * evidence; it must not contain the erased personal data itself.
 */
final readonly class PrivacyErasureReceiptData
{
    /**
     * @param  list<string>  $erasedFiles
     */
    public function __construct(
        public string $contributor,
        public PrivacyErasureMode $mode,
        public int $affectedRecords,
        public array $erasedFiles = [],
        public bool $suppressionMarkerRetained = false,
    ) {
        throw_if($affectedRecords < 0, InvalidArgumentException::class, 'An erasure receipt cannot report a negative record count.');

        foreach ($erasedFiles as $file) {
            throw_unless(preg_match('/^[A-Za-z0-9_.-]+:[^\s]+$/D', $file) === 1, InvalidArgumentException::class, 'An erased file is reported as disk:path.');
        }
    }

    /**
     * @return array{contributor: string, mode: string, affected_records: int, erased_files: list<string>, suppression_marker_retained: bool}
     */
    public function toArray(): array
    {
        return [
            'contributor' => $this->contributor,
            'mode' => $this->mode->value,
            'affected_records' => $this->affectedRecords,
            'erased_files' => $this->erasedFiles,
            'suppression_marker_retained' => $this->suppressionMarkerRetained,
        ];
    }
}
