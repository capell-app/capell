<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\PhpFileEditor;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use RuntimeException;
use Throwable;

class ThemeSourcesPatch implements Patch
{
    private const string THEME_CSS_PATH = 'resources/css/filament/admin/theme.css';

    private const string ADMIN_PANEL_PROVIDER_PATH = 'app/Providers/Filament/AdminPanelProvider.php';

    private const array REQUIRED_SOURCES = [
        "@source '../../../../vendor/capell-app/admin/resources/views/**/*.blade.php';",
        "@source '../../../../vendor/capell-app/installer/resources/views/**/*.blade.php';",
        "@source '../../../../vendor/capell-app/marketplace/resources/views/**/*.blade.php';",
        "@source '../../../../storage/capell/tailwind-classes.txt';",
        "@source '../../../../app/Filament/**/*';",
        "@source '../../../../resources/views/filament/**/*';",
    ];

    public function id(): string
    {
        return 'theme-sources-patch';
    }

    public function group(): string
    {
        return 'themes';
    }

    public function label(): string
    {
        return __('capell-installer::install-guide.theme_sources_patch_label');
    }

    public function description(): string
    {
        return __('capell-installer::install-guide.theme_sources_patch_description');
    }

    public function docUrl(): ?string
    {
        return 'https://filamentphp.com/docs/3.x/admin/themes#creating-a-custom-theme';
    }

    public function defaultEnabled(): bool
    {
        return true;
    }

    public function probe(): PatchStatus
    {
        $themeCssPath = $this->themeCssPath();

        if (! file_exists($themeCssPath)) {
            return PatchStatus::Customised;
        }

        $content = file_get_contents($themeCssPath);

        if ($content === false) {
            return PatchStatus::Unsupported;
        }

        $missingCount = 0;
        foreach (self::REQUIRED_SOURCES as $requiredLine) {
            if (! str_contains($content, $requiredLine)) {
                $missingCount++;
            }
        }

        if ($missingCount === 0) {
            return PatchStatus::AlreadyApplied;
        }

        // Some lines missing, some present = partial state
        return PatchStatus::Applicable;
    }

    public function reason(): ?string
    {
        return null;
    }

    public function apply(): void
    {
        $themeCssPath = $this->themeCssPath();

        throw_unless(
            file_exists($themeCssPath),
            RuntimeException::class,
            'Theme CSS file not found at: ' . $themeCssPath,
        );

        $status = $this->probe();
        if ($status !== PatchStatus::Applicable) {
            throw new RuntimeException(
                'Cannot apply patch when status is: ' . $status->value,
            );
        }

        try {
            $content = file_get_contents($themeCssPath);

            throw_unless(
                $content !== false,
                RuntimeException::class,
                'Could not read theme CSS file',
            );

            // Collect missing lines
            $linesToAdd = [];
            foreach (self::REQUIRED_SOURCES as $requiredLine) {
                if (! str_contains($content, $requiredLine)) {
                    $linesToAdd[] = $requiredLine;
                }
            }

            // Append missing lines
            if ($linesToAdd !== []) {
                $appendContent = "\n" . implode("\n", $linesToAdd) . "\n";
                $content .= $appendContent;

                $writeResult = file_put_contents($themeCssPath, $content);

                throw_unless(
                    $writeResult !== false,
                    RuntimeException::class,
                    'Could not write to theme CSS file',
                );
            }
        } catch (RuntimeException $runtimeException) {
            throw $runtimeException;
        } catch (Throwable $throwable) {
            throw new RuntimeException(
                'Failed to apply ThemeSourcesPatch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }

    private function themeCssPath(): string
    {
        $adminPanelProviderPath = base_path(self::ADMIN_PANEL_PROVIDER_PATH);

        if (! file_exists($adminPanelProviderPath)) {
            return base_path(self::THEME_CSS_PATH);
        }

        try {
            $editor = new PhpFileEditor($adminPanelProviderPath);
            $configuredThemePath = $this->configuredViteThemePath($editor->getAst());

            if ($configuredThemePath !== null) {
                return base_path($configuredThemePath);
            }
        } catch (Throwable) {
            return base_path(self::THEME_CSS_PATH);
        }

        return base_path(self::THEME_CSS_PATH);
    }

    /**
     * @param  array<Node>  $ast
     */
    private function configuredViteThemePath(array $ast): ?string
    {
        foreach ($ast as $node) {
            $themePath = $this->configuredViteThemePathFromNode($node);

            if ($themePath !== null) {
                return $themePath;
            }
        }

        return null;
    }

    private function configuredViteThemePathFromNode(Node $node): ?string
    {
        if (
            $node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'viteTheme'
        ) {
            $firstArgument = $node->getArgs()[0]->value ?? null;

            if ($firstArgument instanceof String_) {
                return $firstArgument->value;
            }

            if ($firstArgument instanceof Array_) {
                foreach ($firstArgument->items as $item) {
                    if ($item?->value instanceof String_) {
                        return $item->value->value;
                    }
                }
            }
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};

            if ($subNode instanceof Node) {
                $themePath = $this->configuredViteThemePathFromNode($subNode);

                if ($themePath !== null) {
                    return $themePath;
                }
            }

            if (is_array($subNode)) {
                foreach ($subNode as $nestedNode) {
                    if (! $nestedNode instanceof Node) {
                        continue;
                    }

                    $themePath = $this->configuredViteThemePathFromNode($nestedNode);

                    if ($themePath !== null) {
                        return $themePath;
                    }
                }
            }
        }

        return null;
    }
}
