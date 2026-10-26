<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\FilePatchPlan;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\ReviewableFilePatch;
use Capell\Installer\Support\InstallGuide\PlansFilePatch;
use Override;
use RuntimeException;

final class ViteThemeInputPatch implements ReviewableFilePatch
{
    use PlansFilePatch;

    private const string THEME_PATH = 'resources/css/filament/admin/theme.css';

    private const string VITE_CONFIG_PATH = 'vite.config.js';

    #[Override]
    public function id(): string
    {
        return 'vite-theme-input-patch';
    }

    #[Override]
    public function group(): string
    {
        return 'themes';
    }

    #[Override]
    public function label(): string
    {
        return __('capell-installer::install-guide.vite_theme_input_patch_label');
    }

    #[Override]
    public function description(): string
    {
        return __('capell-installer::install-guide.vite_theme_input_patch_description');
    }

    #[Override]
    public function docUrl(): string
    {
        return 'https://filamentphp.com/docs/5.x/styling/overview#creating-a-custom-theme';
    }

    #[Override]
    public function defaultEnabled(): bool
    {
        return true;
    }

    #[Override]
    public function probe(): PatchStatus
    {
        $viteConfigPath = base_path(self::VITE_CONFIG_PATH);

        if (! is_file($viteConfigPath)) {
            return PatchStatus::Unsupported;
        }

        $contents = file_get_contents($viteConfigPath);

        if (! is_string($contents)) {
            return PatchStatus::Unsupported;
        }

        if (str_contains($contents, self::THEME_PATH)) {
            return PatchStatus::AlreadyApplied;
        }

        return $this->inputArrayMatches($contents)
            ? PatchStatus::Applicable
            : PatchStatus::Customised;
    }

    #[Override]
    public function reason(): ?string
    {
        return null;
    }

    protected function plan(): FilePatchPlan
    {
        throw_if($this->probe() !== PatchStatus::Applicable, RuntimeException::class, 'The Vite theme input patch is not applicable.');

        $viteConfigPath = base_path(self::VITE_CONFIG_PATH);
        $contents = file_get_contents($viteConfigPath);

        throw_unless(is_string($contents), RuntimeException::class, 'Could not read vite.config.js.');

        $updatedContents = preg_replace_callback(
            '/(\binput\s*:\s*\[)([^\]]*?)(\])/s',
            static function (array $matches): string {
                $input = rtrim($matches[2]);
                $separator = str_ends_with($input, ',') ? ' ' : ', ';

                return $matches[1] . $input . $separator . "'" . self::THEME_PATH . "'" . $matches[3];
            },
            $contents,
            1,
        );

        throw_if(! is_string($updatedContents) || $updatedContents === $contents, RuntimeException::class, 'Could not register the Filament theme in vite.config.js.');

        return new FilePatchPlan(
            $viteConfigPath,
            self::VITE_CONFIG_PATH,
            $contents,
            $updatedContents,
            text: true,
            safeAfter: "'" . self::THEME_PATH . "'",
        );
    }

    private function inputArrayMatches(string $contents): bool
    {
        if (! preg_match('/\binput\s*:\s*\[([^\]]*?)\]/s', $contents, $matches)) {
            return false;
        }

        return preg_match('/[\'\"]resources\/(?:css|js)\//', $matches[1]) === 1;
    }
}
