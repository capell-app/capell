<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use Spatie\LaravelData\Data;

/**
 * What happened when the panel handed a removal to whatever performs removals
 * on this site.
 *
 * Deliberately not the same shape as ExtensionPackageUninstallResultData: that
 * one reports a removal that has *finished*, and the whole point of the queued
 * path is that this one has not. "Accepted" and "succeeded" are different
 * claims, and a shared type would let a surface make the second while meaning
 * the first.
 */
final class ExtensionRemovalOutcomeData extends Data
{
    public function __construct(
        public readonly bool $accepted,
        public readonly string $title,
        public readonly string $body,
    ) {}

    public static function accepted(string $title, string $body): self
    {
        return new self(accepted: true, title: $title, body: $body);
    }

    public static function refused(string $title, string $body): self
    {
        return new self(accepted: false, title: $title, body: $body);
    }
}
