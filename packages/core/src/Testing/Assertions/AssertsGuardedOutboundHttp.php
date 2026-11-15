<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Assertions;

use AssertionError;
use Capell\Core\Support\Security\OutboundHttpSourceScanner;

/**
 * Holds a package's outbound HTTP to its `security.externalHttpClients` declaration.
 *
 * Every class in `src` that opens an outbound connection must be listed in `clients`, and every listed client must
 * still open one or be a hand-declared SDK wrapper in `manualClients`. Stream reads whose target cannot be classified
 * must carry a reviewed declaration, and requests through the `Http` facade must set a timeout; addresses that come
 * from configuration, content or a user belong on `OutboundHttp::guarded()`, which always applies its policy's
 * timeouts and address checks.
 */
final class AssertsGuardedOutboundHttp
{
    public const string CONTRACT_ID = 'security.outbound-http';

    public static function run(string $packageRoot, string $manifestPath): void
    {
        $scanner = new OutboundHttpSourceScanner;
        $declaration = self::declaration($manifestPath);
        $expected = array_values(array_unique([...$scanner->clientClasses($packageRoot), ...$declaration['manualClients']]));
        sort($expected);
        $problems = [];

        foreach (array_diff($expected, $declaration['clients']) as $class) {
            $problems[] = sprintf('%s opens outbound connections but is not listed in security.externalHttpClients.clients.', $class);
        }

        foreach (array_diff($declaration['clients'], $expected) as $class) {
            $problems[] = sprintf('%s is listed in security.externalHttpClients.clients but opens no outbound connection; remove it, or list an SDK wrapper in manualClients.', $class);
        }

        foreach ($scanner->streamProblems($packageRoot) as $problem) {
            $problems[] = $problem;
        }

        foreach ($scanner->requestsWithoutTimeouts($packageRoot) as $location) {
            $problems[] = sprintf('%s sends a request without a timeout; set one, or use OutboundHttp::guarded().', $location);
        }

        throw_if($problems !== [], AssertionError::class, sprintf("[%s] %s:\n- %s", self::CONTRACT_ID, $packageRoot, implode("\n- ", $problems)));
    }

    /** @return array{clients: list<string>, manualClients: list<string>} */
    private static function declaration(string $manifestPath): array
    {
        $manifest = json_decode((string) @file_get_contents($manifestPath), true);
        $security = is_array($manifest) && is_array($manifest['security'] ?? null) ? $manifest['security'] : [];
        $clients = is_array($security['externalHttpClients'] ?? null) ? $security['externalHttpClients'] : [];

        return [
            'clients' => self::stringList($clients['clients'] ?? []),
            'manualClients' => self::stringList($clients['manualClients'] ?? []),
        ];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }
}
