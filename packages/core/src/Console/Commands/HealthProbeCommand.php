<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Illuminate\Console\Command;

/**
 * Prove that a *fresh* PHP process can still boot this application and read its
 * package registry.
 *
 * This is the check a package operation cannot perform on itself. The worker
 * that just installed, updated or removed a package is holding an autoloader,
 * a container and an opcode cache from before the change; it can be perfectly
 * healthy while the next real request fatals on a provider that no longer
 * exists. Only a new process reading the files as they are now can tell.
 *
 * It is deliberately better than a loopback HTTP request: it needs no web
 * server, no public hostname and no route, so it works on hosts that cannot
 * reach themselves — and it validates bootstrap and autoload state rather than
 * merely proving something answers on a port.
 *
 * Hidden because it is machinery, not an operator tool: it takes no arguments,
 * prints one line, and exists to be exec'd.
 */
final class HealthProbeCommand extends Command
{
    protected $signature = 'capell:health-probe';

    protected $description = 'Verify that a fresh process can boot Capell and read its package registry';

    protected $hidden = true;

    public function handle(CapellPackageRegistry $registry): int
    {
        $manifests = new ManifestLoader(new ManifestValidator)->discover();
        $registry->fill($manifests);

        // Reaching this line is the whole result. The count travels with it so
        // the caller's captured output says something useful when an operator
        // reads it back off a failed operation's timeline.
        $this->line(sprintf('capell:health-probe ok packages=%d', count($manifests)));

        return self::SUCCESS;
    }
}
