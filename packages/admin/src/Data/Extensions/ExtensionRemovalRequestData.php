<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use Spatie\LaravelData\Data;

/**
 * What the operator asked for on the uninstall modal.
 *
 * Carries the dependent packages the removal has to take with it, not just the
 * one that was clicked: an extension nothing else depends on is the easy case,
 * and the modal has already made the operator confirm the rest.
 */
final class ExtensionRemovalRequestData extends Data
{
    /**
     * @param  list<string>  $packageNames  Every package this removal covers, in
     *                                      the order they must come off.
     */
    public function __construct(
        public readonly string $composerName,
        public readonly array $packageNames,
        public readonly bool $deletePackage,
        public readonly bool $deleteData,
        public readonly string $extensionSlug = '',
        public readonly string $extensionName = '',
        public readonly string $kind = 'plugin',
    ) {}
}
