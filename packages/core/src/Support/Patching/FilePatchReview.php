<?php

declare(strict_types=1);

namespace Capell\Core\Support\Patching;

use Capell\Core\Data\Patching\PatchFilePreviewData;
use RuntimeException;

/** A request-local chain of reviewed sources, advanced only by successful writes. */
final class FilePatchReview
{
    /** @var array<string, string> */
    private array $sources = [];

    /** @param list<PatchFilePreviewData> $files */
    public function __construct(array $files)
    {
        foreach ($files as $file) {
            if ($file->sourceFingerprint === null) {
                continue;
            }

            throw_if(isset($this->sources[$file->path]) && $this->sources[$file->path] !== $file->sourceFingerprint, RuntimeException::class, 'Conflicting reviewed sources.');
            $this->sources[$file->path] = $file->sourceFingerprint;
        }
    }

    public function assertMatches(FilePatchPlan $plan): void
    {
        $expected = $this->sources[$plan->relativePath] ?? null;
        throw_unless($expected !== null && hash_equals($expected, $plan->sourceFingerprint($plan->before)), RuntimeException::class, 'Patch source differs from the reviewed source.');
    }

    public function recordApplied(FilePatchPlan $plan): void
    {
        // Later selected patches may build on exactly this authorised output.
        $this->sources[$plan->relativePath] = $plan->sourceFingerprint($plan->after);
    }
}
