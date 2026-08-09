<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Blueprints;

use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Support\BlueprintSubjectRegistry;

/**
 * The one place admin turns blueprint subject descriptors into select options.
 *
 * **Why this is not a backed enum.** Admin's house rule is that Filament option
 * lists come from a backed enum implementing `HasLabel`, never an inline array.
 * That rule assumes a closed set. Since CAP-0100.2 the blueprint subject set is
 * open: any installed package can contribute a subject at boot, so the option
 * list is only known at runtime and PHP cannot generate an enum for it. The
 * spec's `BlueprintSubjectOptionEnum` bridge is not expressible for the same
 * reason.
 *
 * This class implements the rule's intent instead of its letter. The intent is
 * that option pairs are produced in exactly one reviewable place rather than
 * being reassembled inline at each call site, so a change to how subjects are
 * labelled or ordered lands once. Every admin surface that offers a subject
 * choice — the blueprint create form, subject filters, listing tabs — goes
 * through here. If you are adding another, call this rather than mapping the
 * registry yourself.
 *
 * @see BlueprintSubjectRegistry The source of truth these options are read from.
 */
final class BlueprintSubjectOptions
{
    /**
     * Every registered subject, as `key => label`, ordered by label.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $options = [];

        foreach (self::registry()->all() as $key => $descriptor) {
            $options[$key] = $descriptor->label;
        }

        asort($options);

        return $options;
    }

    /**
     * Subjects that may carry blueprints in the given group.
     *
     * @return array<string, string>
     */
    public static function forGroup(BlueprintGroupEnum $group): array
    {
        $options = [];

        foreach (self::registry()->all() as $key => $descriptor) {
            if ($descriptor->allowsGroup($group)) {
                $options[$key] = $descriptor->label;
            }
        }

        asort($options);

        return $options;
    }

    /**
     * Operator-facing label for a stored subject key.
     *
     * An unregistered key means the contributing package is uninstalled while
     * its blueprint rows survive. The owning package is deliberately NOT named
     * in that case: `blueprints.type` stores only the key, and the descriptor
     * that carried `ownerPackage` disappeared with the package, so there is no
     * honest source for it. Naming a package here would mean inventing one.
     */
    public static function label(string $subjectKey): string
    {
        $descriptor = self::registry()->descriptorOrNull($subjectKey);

        if ($descriptor instanceof BlueprintSubjectDescriptorData) {
            return $descriptor->label;
        }

        return __('capell-admin::generic.unavailable_subject', ['key' => $subjectKey]);
    }

    /**
     * Composer name of the package owning a subject, when it is still installed.
     */
    public static function ownerPackage(string $subjectKey): ?string
    {
        return self::registry()->descriptorOrNull($subjectKey)?->ownerPackage;
    }

    public static function isAvailable(string $subjectKey): bool
    {
        return self::registry()->has($subjectKey);
    }

    private static function registry(): BlueprintSubjectRegistry
    {
        return resolve(BlueprintSubjectRegistry::class);
    }
}
