<?php

declare(strict_types=1);

namespace Capell\Core\Support\Composer;

use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * The composer.json / composer.lock pair as they were before a Composer-mutating
 * operation started, and the two steps that put them back.
 *
 * This is deliberately operation-agnostic. A removal, an install, an update and
 * an uninstall all mutate exactly the same two files plus vendor/, so they all
 * need exactly the same recovery: rewrite the two files, then make vendor/ agree
 * with them again. Having each operation carry its own copy of that sequence is
 * how a recovery path ends up never being exercised — the one path that has to
 * work when everything else has already failed.
 */
final class ComposerStateSnapshot
{
    /**
     * Matches the timeout the package removal has always used for its recovery
     * `composer install`. Callers with a real budget to spend should pass it.
     */
    public const int DEFAULT_TIMEOUT_SECONDS = 300;

    /**
     * The message an operator sees when the files were put back but vendor/ could
     * not be made to agree with them. It is deliberately explicit that Composer
     * output was withheld: this path handles authenticated installs, so the
     * output may carry credentials.
     */
    public const string UNRECOVERABLE_MESSAGE = 'Composer could not restore the package installation automatically. Composer output was withheld because it may contain credentials. '
        . 'Run composer install from the application root in a trusted terminal before retrying.';

    private function __construct(
        private readonly Filesystem $files,
        private readonly string $applicationPath,
        public readonly string $composerPath,
        public readonly string $lockPath,
        public readonly ?string $composerContents,
        public readonly ?string $lockContents,
        public readonly string $installedManifestPath,
        private readonly ?string $installedManifestContents,
    ) {}

    public static function capture(Filesystem $files, ?string $applicationPath = null): self
    {
        $root = rtrim($applicationPath ?? base_path(), DIRECTORY_SEPARATOR);
        $composerPath = $root . DIRECTORY_SEPARATOR . 'composer.json';
        $lockPath = $root . DIRECTORY_SEPARATOR . 'composer.lock';
        $installedManifestPath = implode(DIRECTORY_SEPARATOR, [$root, 'vendor', 'composer', 'installed.json']);

        return new self(
            files: $files,
            applicationPath: $root,
            composerPath: $composerPath,
            lockPath: $lockPath,
            composerContents: $files->exists($composerPath) ? $files->get($composerPath) : null,
            lockContents: $files->exists($lockPath) ? $files->get($lockPath) : null,
            installedManifestPath: $installedManifestPath,
            installedManifestContents: $files->exists($installedManifestPath) ? $files->get($installedManifestPath) : null,
        );
    }

    /**
     * Whether the Composer state on disk is still byte-identical to the snapshot.
     *
     * When it is, nothing this operation did needs undoing: vendor/ still agrees
     * with the same composer.lock it agreed with before, so a recovery
     * `composer install` would rebuild the state that is already there. That
     * makes it pure risk — it is a network-dependent, minutes-long subprocess
     * run at the exact moment the caller is already handling a failure.
     *
     * Three files are compared, not two. composer.json and composer.lock say
     * what was asked for; vendor/composer/installed.json says what is actually
     * installed, and it is the only one of the three that a vendor-mutating
     * operation which leaves the requirements alone still has to rewrite —
     * `composer install --no-dev`, a vendor prune, and the uninstall path all
     * change it. Comparing only the requirement manifests would let those look
     * like nothing happened.
     *
     * The assumption that remains, stated here so callers do not inherit it
     * unexamined: a mutation of vendor/ that rewrites no Composer manifest at
     * all is invisible to this check. Nothing Composer itself does falls in that
     * category, but arbitrary application code can — the post-autoload-dump
     * script replay runs the host application's own scripts, which may publish
     * or delete files under vendor/. A caller whose failure path can follow such
     * a step must not rely on this guard alone to decide that vendor/ is intact.
     * Closing that properly needs a content signal over the vendor tree, which
     * costs more to compute than the recovery install it would be protecting.
     */
    public function matchesDisk(): bool
    {
        return $this->contentsOnDisk($this->composerPath) === $this->composerContents
            && $this->contentsOnDisk($this->lockPath) === $this->lockContents
            && $this->contentsOnDisk($this->installedManifestPath) === $this->installedManifestContents;
    }

    /**
     * Put the two manifests back exactly as they were.
     *
     * A lock file that did not exist when the snapshot was taken is deleted
     * rather than left behind: the absence of it is part of the state being
     * restored.
     */
    public function restoreFiles(): void
    {
        if ($this->composerContents !== null) {
            $this->files->replace($this->composerPath, $this->composerContents);
        }

        if ($this->lockContents !== null) {
            $this->files->replace($this->lockPath, $this->lockContents);

            return;
        }

        if ($this->files->exists($this->lockPath)) {
            $this->files->delete($this->lockPath);
        }
    }

    /**
     * Make vendor/ agree with the restored manifests again.
     *
     * Runs with --no-scripts for the same reason every other Composer call in
     * Capell does: a third-party package's scripts must not execute as the web
     * or queue user. On failure the manifests are restored a second time —
     * `composer install` rewrites composer.lock as it goes, so a run that died
     * part-way can leave the very file this snapshot exists to protect in a
     * state neither the caller nor Composer intended.
     *
     * @param  array<string, string|false>|null  $environment  The environment the caller's other
     *                                                         Composer subprocesses run under. Defaults to the install
     *                                                         environment, so a caller with no special needs matches the
     *                                                         rest of core.
     */
    public function restoreInstalledPackages(
        ProcessFactoryInterface $processFactory,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?array $environment = null,
    ): void {
        $process = $processFactory->make(
            [...new RuntimeBinaryResolver()->composer(), 'install', '--no-interaction', '--no-scripts'],
            $this->applicationPath,
        );
        $process->setEnv($environment ?? ComposerProcessEnvironment::forInstall($_SERVER));
        $process->setTimeout($timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->restoreFiles();

            throw new RuntimeException(self::UNRECOVERABLE_MESSAGE);
        }
    }

    private function contentsOnDisk(string $path): ?string
    {
        return $this->files->exists($path) ? $this->files->get($path) : null;
    }
}
