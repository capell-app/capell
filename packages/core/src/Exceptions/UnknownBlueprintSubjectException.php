<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Capell\Core\Data\PageTypeData;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Support\BlueprintSubjectRegistry;
use RuntimeException;

/**
 * Thrown when a blueprint subject key cannot be resolved to a registered
 * descriptor.
 *
 * Resolution is deliberately fail-closed: an unknown key means either a package
 * bug (publishing a subject it never registered) or an uninstalled package whose
 * blueprint rows outlived it. Neither may silently fall back to the Page
 * subject, which would quietly re-point orphaned rows at the wrong model.
 *
 * Read paths that must survive an uninstalled package should call
 * {@see BlueprintSubjectRegistry::descriptorOrNull()} and
 * render an unavailable-subject state instead of catching this exception.
 */
final class UnknownBlueprintSubjectException extends RuntimeException
{
    /**
     * @param  list<string>  $registeredKeys
     */
    public static function forKey(string $key, array $registeredKeys): self
    {
        return new self(sprintf(
            'Blueprint subject [%s] is not registered. Registered subjects: [%s].',
            $key,
            implode(', ', $registeredKeys),
        ));
    }

    /**
     * A value that is not a subject reference at all reached the type attribute.
     *
     * Passing it through unchanged is how a malformed type lands in the column
     * and only surfaces much later, on read.
     */
    public static function forNonSubjectValue(string $attribute, string $givenType): self
    {
        return new self(sprintf(
            'Blueprint [%s] must be a subject key, a %s, or a %s; got [%s].',
            $attribute,
            BlueprintSubjectEnum::class,
            PageTypeData::class,
            $givenType,
        ));
    }
}
