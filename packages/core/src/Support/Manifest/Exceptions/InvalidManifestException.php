<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest\Exceptions;

use RuntimeException;

final class InvalidManifestException extends RuntimeException
{
    public static function missingField(string $field): self
    {
        return new self('Capell manifest is missing required field: ' . $field);
    }

    public static function invalidKind(string $kind): self
    {
        $valid = implode(', ', ['package', 'plugin', 'theme', 'integration', 'bundle']);

        return new self(sprintf("Capell manifest has invalid kind: '%s'. Must be one of: %s", $kind, $valid));
    }

    public static function invalidContext(string $context): self
    {
        $valid = implode(', ', ['admin', 'frontend', 'console', 'shared']);

        return new self(sprintf("Capell manifest has invalid context: '%s'. Must be one of: %s", $context, $valid));
    }

    public static function fileNotFound(string $path): self
    {
        return new self('Capell manifest not found at: ' . $path);
    }

    public static function invalidField(string $field, string $reason): self
    {
        return new self(sprintf('Capell manifest has invalid field %s: %s', $field, $reason));
    }

    public static function packageNameMismatch(string $composerName, string $manifestName, string $source): self
    {
        return new self(sprintf(
            "Capell manifest package name mismatch for %s: composer.json name '%s' does not match capell.json name '%s'.",
            $source,
            $composerName,
            $manifestName,
        ));
    }
}
