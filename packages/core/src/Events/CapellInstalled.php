<?php

declare(strict_types=1);

namespace Capell\Core\Events;

/**
 * Dispatched by `capell:install` after a successful install when a `--spec`
 * file path is supplied. Core never opens or parses the spec — it only resolves
 * the path and announces it, so an opinionated extension (e.g. ai-creator) can
 * consume the spec and build a site without core depending on that package.
 *
 * Intentionally generic so it can host future post-install extension hooks.
 */
class CapellInstalled
{
    public function __construct(
        public readonly string $specPath,
        public readonly bool $seededDefaults,
    ) {}
}
