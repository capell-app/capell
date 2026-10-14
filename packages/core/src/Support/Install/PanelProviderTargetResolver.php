<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Data\Install\PanelProviderData;
use Capell\Core\Support\Patching\PhpFileEditor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use SplFileInfo;
use Throwable;

final class PanelProviderTargetResolver
{
    /** @return list<string> */
    public function resolve(?string $selection = null, bool $adoptDefault = false): array
    {
        $selectedPath = $selection !== null && $selection !== ''
            ? (str_starts_with($selection, DIRECTORY_SEPARATOR) ? $selection : base_path($selection))
            : null;

        if ($selectedPath !== null && is_file($selectedPath)) {
            $path = realpath($selectedPath) ?: $selectedPath;

            return $this->candidateFor($path) instanceof PanelProviderData ? [$path] : [];
        }

        $candidates = $this->candidates();

        if ($selection !== null && $selection !== '') {
            $selected = $candidates->filter(fn (PanelProviderData $panel): bool => $panel->matches($selection));

            // An ambiguous selection (shared class name or panel id) never targets a guess.
            $only = $selected->count() === 1 ? $selected->first() : null;

            return $only instanceof PanelProviderData ? [$only->path] : [];
        }

        $ownedPath = InstallRunState::authorisedPanelProvider();

        if ($ownedPath !== null) {
            return $candidates->contains(fn (PanelProviderData $panel): bool => $panel->path === $ownedPath) ? [$ownedPath] : [];
        }

        // A replaced or missing recorded target requires explicit adoption.
        if (InstallRunState::hasPanelProviderRecord()) {
            return [];
        }

        $default = $adoptDefault ? $candidates->first() : null;

        return $default instanceof PanelProviderData ? [$default->path] : [];
    }

    /** @return Collection<int, PanelProviderData> */
    public function candidates(): Collection
    {
        return collect($this->providerPaths())
            ->map($this->candidateFor(...))
            ->filter()
            ->values();
    }

    /** @return list<string> */
    public function providerPaths(): array
    {
        $directory = app_path('Providers/Filament');

        if (! is_dir($directory)) {
            return [];
        }

        $files = array_filter(File::allFiles($directory), static fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), 'PanelProvider.php'));
        $paths = array_map(static fn (SplFileInfo $file): string => (string) $file->getRealPath(), $files);
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function candidateFor(string $path): ?PanelProviderData
    {
        try {
            $editor = new PhpFileEditor($path);
            $class = $editor->findClass();

            if (! $class?->name instanceof Identifier) {
                return null;
            }

            $namespace = $editor->findNamespace();
            $method = $editor->findMethodInClass($class->name->name, 'panel');
            $statement = $method?->stmts[0] ?? null;
            $call = $statement instanceof Return_ ? $statement->expr : null;
            $panelId = str($class->name->name)->before('PanelProvider')->kebab()->toString();

            while ($call instanceof MethodCall) {
                $argument = $call->args[0] ?? null;

                if ($call->name instanceof Identifier && $call->name->name === 'id' && $argument instanceof Arg && $argument->value instanceof String_) {
                    $panelId = $argument->value->value;
                    break;
                }

                $call = $call->var;
            }

            return new PanelProviderData(
                path: $path,
                relativePath: InstallRunState::panelProviderKey($path),
                className: ($namespace !== null ? $namespace . '\\' : '') . $class->name->name,
                panelId: $panelId,
            );
        } catch (Throwable) {
            return null;
        }
    }
}
