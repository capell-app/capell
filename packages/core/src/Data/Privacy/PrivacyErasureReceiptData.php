<?php

declare(strict_types=1);

namespace Capell\Core\Data\Privacy;

use Capell\Core\Enums\PrivacyErasureMode;
use InvalidArgumentException;

/**
 * What one package erased for a privacy request: whether it deleted or
 * anonymised (an eraser asked to delete may anonymise records it must keep,
 * such as orders needed for accounting, and says so here), how many records it
 * changed, which stored files it removed (as `disk:path`, never their contents)
 * whether it kept a suppression marker, and which records it deliberately kept
 * and why (for example a submission under legal hold, which erasure must not
 * remove). Kept with the request as evidence; it must not contain the erased
 * personal data itself, so kept records are named only by their numeric ids.
 */
final readonly class PrivacyErasureReceiptData
{
    /** A record kept because an operator placed it under legal hold. */
    public const string RETAINED_LEGAL_HOLD = 'legal_hold';

    /**
     * @param  list<string>  $erasedFiles
     * @param  array<string, list<int>>  $retainedRecords  reason => ids of the records the package kept for that reason
     */
    public function __construct(
        public string $contributor,
        public PrivacyErasureMode $mode,
        public int $affectedRecords,
        public array $erasedFiles = [],
        public bool $suppressionMarkerRetained = false,
        public array $retainedRecords = [],
    ) {
        throw_if($affectedRecords < 0, InvalidArgumentException::class, 'An erasure receipt cannot report a negative record count.');
        PrivacyFileReference::assertAllValid($erasedFiles);

        foreach ($retainedRecords as $reason => $ids) {
            throw_if(! is_string($reason) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $reason) !== 1, InvalidArgumentException::class, 'A retained-record reason must be a lower-case code.');
            throw_if(! is_array($ids) || $ids === [] || ! array_is_list($ids), InvalidArgumentException::class, 'Retained records must be a non-empty list of ids.');

            foreach ($ids as $id) {
                throw_if(! is_int($id) || $id < 1, InvalidArgumentException::class, 'A retained record is named by a positive integer id.');
            }
        }
    }

    /** How many records the package kept, across every reason. */
    public function retainedCount(): int
    {
        return array_sum(array_map(count(...), $this->retainedRecords));
    }

    /**
     * @return array{contributor: string, mode: string, affected_records: int, erased_files: list<string>, suppression_marker_retained: bool, retained_records: array<string, list<int>>}
     */
    public function toArray(): array
    {
        return [
            'contributor' => $this->contributor,
            'mode' => $this->mode->value,
            'affected_records' => $this->affectedRecords,
            'erased_files' => $this->erasedFiles,
            'suppression_marker_retained' => $this->suppressionMarkerRetained,
            'retained_records' => $this->retainedRecords,
        ];
    }
}
