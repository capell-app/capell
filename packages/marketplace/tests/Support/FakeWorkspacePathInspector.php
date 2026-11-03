<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests\Support;

use Capell\Marketplace\Support\NativeWorkspacePathInspector;
use Capell\Marketplace\Support\WorkspacePathInspector;
use Override;

final class FakeWorkspacePathInspector implements WorkspacePathInspector
{
    public NativeWorkspacePathInspector $native;

    public ?string $foreign = null;

    public ?string $groupWritable = null;

    public ?string $stickyWorldWritable = null;

    public ?string $worldWritable = null;

    public ?string $racedRoot = null;

    public ?int $uid;

    public bool $scanFails = false;

    public bool $metadataFails = false;

    public bool $simulateAclModes = false;

    public function __construct()
    {
        $this->native = new NativeWorkspacePathInspector;
        $this->uid = $this->native->effectiveUserId();
    }

    #[Override]
    public function stat(string $path): array|false
    {
        $stat = $this->native->stat($path);
        if ($stat !== false && $this->simulateAclModes && ($stat['mode'] & 01000) !== 0) {
            $stat['mode'] &= ~0022;
        }

        if ($stat !== false && $path === $this->foreign) {
            $stat['uid'] = ($this->uid ?? 1) + 10000;
        }

        if ($stat !== false && $path === $this->groupWritable) {
            $stat['mode'] |= 0020;
        }

        if ($stat !== false && $path === $this->stickyWorldWritable) {
            $stat['mode'] |= 01002;
            $stat['uid'] = 0;
        }

        if ($stat !== false && $path === $this->worldWritable) {
            $stat['mode'] = ($stat['mode'] | 0002) & ~01000;
        }

        return $stat;
    }

    #[Override]
    public function realpath(string $path): string|false
    {
        return $this->native->realpath($path);
    }

    #[Override]
    public function effectiveUserId(): ?int
    {
        return $this->uid;
    }

    #[Override]
    public function mkdir(string $path, int $mode): bool
    {
        if ($path === $this->racedRoot) {
            $this->native->mkdir($path, $mode);

            return false;
        }

        return $this->native->mkdir($path, $mode);
    }

    #[Override]
    public function glob(string $pattern): array|false
    {
        return $this->scanFails ? false : $this->native->glob($pattern);
    }

    #[Override]
    public function modifiedAt(string $path): int|false
    {
        return $this->metadataFails ? false : $this->native->modifiedAt($path);
    }
}
