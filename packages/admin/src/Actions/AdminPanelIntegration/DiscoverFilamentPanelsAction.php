<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\AdminPanelIntegration;

use Capell\Admin\Data\AdminPanelIntegration\AdminPanelCandidateData;
use Capell\Core\Support\Patching\PhpFileEditor;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsObject;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Return_;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

final class DiscoverFilamentPanelsAction
{
    use AsObject;

    /**
     * @return Collection<int, AdminPanelCandidateData>
     */
    public function handle(): Collection
    {
        $panelDirectory = app_path('Providers/Filament');

        if (! is_dir($panelDirectory)) {
            return collect();
        }

        $finder = Finder::create()
            ->files()
            ->in($panelDirectory)
            ->name('*PanelProvider.php')
            ->sortByName();

        return collect(iterator_to_array($finder))
            ->map(fn (SplFileInfo $file): ?AdminPanelCandidateData => $this->candidateFor((string) $file->getRealPath()))
            ->filter()
            ->values();
    }

    private function candidateFor(string $path): ?AdminPanelCandidateData
    {
        try {
            $editor = new PhpFileEditor($path);
            $class = $editor->findClass();

            if (! $class instanceof Class_ || ! $class->name instanceof Identifier) {
                return null;
            }

            $namespace = $editor->findNamespace();
            $className = ($namespace !== null ? $namespace . '\\' : '') . $class->name->name;
            $contents = $editor->originalContent();

            return new AdminPanelCandidateData(
                path: $path,
                relativePath: str($path)->after(base_path() . DIRECTORY_SEPARATOR)->toString(),
                className: $className,
                panelId: $this->panelIdFrom($editor) ?? str($class->name->name)->before('PanelProvider')->kebab()->toString(),
                alreadyIntegrated: str_contains($contents, 'CapellAdminPlugin'),
                registered: $this->isRegistered($className),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function panelIdFrom(PhpFileEditor $editor): ?string
    {
        $class = $editor->findClass();
        $method = $editor->findMethodInClass($class?->name?->name, 'panel');
        $statement = $method?->stmts[0] ?? null;
        $methodCall = $statement instanceof Return_ ? $statement->expr : null;

        while ($methodCall instanceof MethodCall) {
            if ($methodCall->name instanceof Identifier && $methodCall->name->name === 'id' && isset($methodCall->args[0])) {
                $argument = $methodCall->args[0];

                if (! $argument instanceof Arg) {
                    return null;
                }

                $value = $argument->value;

                return property_exists($value, 'value') && is_string($value->value) ? $value->value : null;
            }

            $methodCall = $methodCall->var;
        }

        return null;
    }

    private function isRegistered(string $className): bool
    {
        $providerFiles = [
            base_path('bootstrap/providers.php'),
            config_path('app.php'),
        ];

        return array_any($providerFiles, fn (string $providerFile): bool => file_exists($providerFile) && str_contains((string) file_get_contents($providerFile), $className));
    }
}
