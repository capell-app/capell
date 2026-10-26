<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\FilePatchPlan;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\PhpFileEditor;
use Capell\Core\Support\Patching\ReviewableFilePatch;
use Capell\Installer\Support\InstallGuide\PlansFilePatch;
use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use RuntimeException;
use Throwable;

class ThemeSourcesPatch implements ReviewableFilePatch
{
    use PlansFilePatch;

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

    #[Override]
    public function id(): string
    {
        return 'theme-sources-patch';
    }

    #[Override]
    public function group(): string
    {
        return 'themes';
    }

    #[Override]
    public function label(): string
    {
        return __('capell-installer::install-guide.theme_sources_patch_label');
    }

    #[Override]
    public function description(): string
    {
        return __('capell-installer::install-guide.theme_sources_patch_description');
    }

    #[Override]
    public function docUrl(): ?string
    {
        return 'https://filamentphp.com/docs/3.x/admin/themes#creating-a-custom-theme';
    }

    #[Override]
    public function defaultEnabled(): bool
    {
        return true;
    }

    #[Override]
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

    #[Override]
    public function reason(): ?string
    {
        return null;
    }

    protected function plan(): FilePatchPlan
    {
        $path = $this->themeCssPath();
        $content = file_get_contents($path);
        throw_unless(is_string($content), RuntimeException::class, 'Could not read theme CSS file');
        $lines = array_values(array_filter(self::REQUIRED_SOURCES, static fn (string $line): bool => ! str_contains($content, $line)));
        $addition = "\n" . implode("\n", $lines) . "\n";

        return new FilePatchPlan(
            $path,
            ltrim(str_replace(base_path(), '', $path), '/'),
            $content,
            $content . $addition,
            text: true,
            safeAfter: implode("\n", $lines),
        );
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
