<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest\Exceptions;

use Capell\Core\Enums\ExtensionManifestVersion;
use RuntimeException;

final class InvalidManifestException extends RuntimeException
{
    /**
     * @param  string|null  $field  The manifest path the problem belongs to, so callers that
     *                              collect problems per field can key them without parsing the message.
     */
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public static function missingField(string $field): self
    {
        return new self('Capell manifest is missing required field: ' . $field, $field);
    }

    public static function unsupportedManifestVersion(mixed $declared): self
    {
        $supported = ExtensionManifestVersion::V3->value;

        if ($declared === null) {
            return new self(sprintf('Capell manifest is missing required field: manifest-version. Capell reads manifest-version %d.', $supported), 'manifest-version');
        }

        return new self(sprintf(
            'Capell manifest declares manifest-version %s, but Capell reads manifest-version %d only.',
            is_scalar($declared) ? var_export($declared, true) : get_debug_type($declared),
            $supported,
        ), 'manifest-version');
    }

    public static function invalidKind(string $kind): self
    {
        $valid = implode(', ', ['package', 'plugin', 'theme', 'integration', 'bundle']);

        return new self(sprintf("Capell manifest has invalid kind: '%s'. Must be one of: %s", $kind, $valid), 'kind');
    }

    /**
     * @param  list<string>  $valid
     */
    public static function invalidSurface(string $surface, array $valid): self
    {
        return new self(sprintf("Capell manifest has invalid surface: '%s'. Must be one of: %s", $surface, implode(', ', $valid)), 'surfaces');
    }

    /**
     * @param  list<string>  $unexpected
     * @param  list<string>  $missing
     * @param  list<string>  $required
     * @param  list<string>  $valid
     */
    public static function invalidProviderBuckets(array $unexpected, array $missing, array $required, array $valid): self
    {
        $problems = [];

        if ($unexpected !== []) {
            $problems[] = sprintf('unknown bucket %s', implode(', ', array_map(static fn (string $bucket): string => "'" . $bucket . "'", $unexpected)));
        }

        if ($missing !== []) {
            $problems[] = sprintf('missing bucket %s', implode(', ', array_map(static fn (string $bucket): string => "'" . $bucket . "'", $missing)));
        }

        return new self(sprintf(
            'Capell manifest has invalid providers: %s. Required buckets: %s. Allowed buckets: %s',
            implode('; ', $problems),
            implode(', ', $required),
            implode(', ', $valid),
        ), 'providers');
    }

    public static function fileNotFound(string $path): self
    {
        return new self('Capell manifest not found at: ' . $path);
    }

    public static function invalidField(string $field, string $reason): self
    {
        return new self(sprintf('Capell manifest has invalid field %s: %s', $field, $reason), $field);
    }

    public static function packageNameMismatch(string $composerName, string $manifestName, string $source): self
    {
        return new self(sprintf(
            "Capell manifest package name mismatch for %s: composer.json name '%s' does not match capell.json name '%s'.",
            $source,
            $composerName,
            $manifestName,
        ), 'name');
    }
}
