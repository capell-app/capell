<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Contracts;

use AssertionError;
use Capell\Core\Testing\Assertions\AssertsDeclaredThrottles;
use Capell\Core\Testing\Assertions\AssertsGuardedOutboundHttp;
use Capell\Core\Testing\Assertions\AssertsPrivacyContributors;
use Capell\Core\Testing\Assertions\AssertsPublicViewSafety;

/**
 * The decision records with which a package opts out of a mandatory static contract check.
 *
 * A record lives at `<package>/docs/decisions/contract-opt-out-<check id>.md` and must hold a `## Decision` and a
 * `## Reason` section, each with text. A record for a check that cannot be opted out, or one missing either section,
 * fails the contract so that an opt-out is never silent.
 */
final readonly class ContractOptOutRecords
{
    public const string CONTRACT_ID = 'contract.opt-out';

    /** @var list<string> */
    public const array OPTIONAL_CHECKS = [
        AssertsGuardedOutboundHttp::CONTRACT_ID,
        AssertsPublicViewSafety::CONTRACT_ID,
        AssertsDeclaredThrottles::CONTRACT_ID,
        AssertsPrivacyContributors::CONTRACT_ID,
    ];

    private const string PREFIX = 'contract-opt-out-';

    /** @param list<string> $optedOut */
    private function __construct(private array $optedOut) {}

    public static function forPackage(string $packageRoot): self
    {
        $directory = rtrim($packageRoot, DIRECTORY_SEPARATOR) . '/docs/decisions';
        $optedOut = [];

        foreach (glob($directory . '/' . self::PREFIX . '*.md') ?: [] as $path) {
            $check = substr(basename($path, '.md'), strlen(self::PREFIX));

            throw_unless(in_array($check, self::OPTIONAL_CHECKS, true), AssertionError::class, sprintf(
                '[%s] %s: [%s] cannot be opted out of; only %s can.',
                self::CONTRACT_ID,
                $path,
                $check,
                implode(', ', self::OPTIONAL_CHECKS),
            ));

            foreach (['Decision', 'Reason'] as $section) {
                throw_if(self::sectionBody((string) file_get_contents($path), $section) === '', AssertionError::class, sprintf(
                    '[%s] %s: the decision record needs a "## %s" section with text.',
                    self::CONTRACT_ID,
                    $path,
                    $section,
                ));
            }

            $optedOut[] = $check;
        }

        return new self($optedOut);
    }

    public function allows(string $check): bool
    {
        return in_array($check, $this->optedOut, true);
    }

    private static function sectionBody(string $markdown, string $heading): string
    {
        if (preg_match('/^##[ \t]+' . preg_quote($heading, '/') . '[ \t]*\R(.*?)(?=^#{1,2}[ \t]|\z)/ms', $markdown, $match) !== 1) {
            return '';
        }

        return trim($match[1]);
    }
}
