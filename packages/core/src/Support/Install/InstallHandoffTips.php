<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Data\InstallInputData;

/**
 * Short, install-shaped "what next" tips printed after the install handoff table.
 *
 * Every tip is derived from facts the installer already recorded (selected packages,
 * created administrator, first-page state, frontend build state). Nothing here queries
 * the database, and no credential is ever included.
 */
final class InstallHandoffTips
{
    public const string KEY_REVIEW = 'review';

    public const string KEY_FRONTEND_BUILD = 'frontend_build';

    public const string KEY_SIGN_IN = 'sign_in';

    public const string KEY_FIRST_PAGE = 'first_page';

    public const string KEY_THEME = 'theme';

    private const string THEME_PACKAGE_PREFIX = 'capell-app/theme-';

    private const int MAX_PACKAGE_TIPS = 2;

    /**
     * Content packages that do nothing useful until an editor adds the first record.
     * Order is priority order: the first installed entries win the two package tip slots.
     *
     * @var list<string>
     */
    private const array CONTENT_PACKAGES = [
        'capell-app/blog',
        'capell-app/form-builder',
        'capell-app/events',
        'capell-app/knowledge-base',
        'capell-app/bookings',
    ];

    /**
     * @param  list<string>  $selectedPackages
     * @return list<array{key: string, label: string, detail: string}>
     */
    public function build(
        InstallInputData $inputData,
        array $selectedPackages,
        bool $completed,
        bool $needsFrontendBuild,
        ?string $adminUrl,
        ?string $adminEmail,
        string $publicUrl,
        string $firstPageStatus,
    ): array {
        if (! $completed) {
            return [$this->tip(self::KEY_REVIEW, 'review')];
        }

        $tips = [];

        if ($needsFrontendBuild) {
            $tips[] = $this->tip(self::KEY_FRONTEND_BUILD, 'frontend_build', [
                'command' => 'php artisan capell:frontend-after-install --apply --no-interaction',
            ]);
        }

        $tips[] = $this->signInTip($inputData, $adminUrl, $adminEmail);

        if ($needsFrontendBuild) {
            return $tips;
        }

        $firstPage = $this->firstPageTip($inputData, $publicUrl, $firstPageStatus);

        if ($firstPage !== null) {
            $tips[] = $firstPage;
        }

        $theme = $this->themeTip($inputData, $selectedPackages);

        if ($theme !== null) {
            $tips[] = $theme;
        }

        return [...$tips, ...$this->contentPackageTips($selectedPackages)];
    }

    /**
     * @return array{key: string, label: string, detail: string}
     */
    private function signInTip(InstallInputData $inputData, ?string $adminUrl, ?string $adminEmail): array
    {
        if ($adminUrl === null) {
            return $this->tip(self::KEY_SIGN_IN, 'sign_in_no_admin');
        }

        $email = $adminEmail !== null && trim($adminEmail) !== '' ? trim($adminEmail) : null;

        if ($email !== null) {
            return $this->tip(self::KEY_SIGN_IN, 'sign_in_as', ['url' => $adminUrl, 'email' => $email]);
        }

        return $this->tip(
            self::KEY_SIGN_IN,
            $inputData->userId !== null ? 'sign_in_existing' : 'sign_in',
            ['url' => $adminUrl],
        );
    }

    /**
     * @return array{key: string, label: string, detail: string}|null
     */
    private function firstPageTip(InstallInputData $inputData, string $publicUrl, string $firstPageStatus): ?array
    {
        if ($inputData->demoContent && $firstPageStatus !== 'missing') {
            return $this->tip(self::KEY_FIRST_PAGE, 'first_page_demo', ['url' => $publicUrl]);
        }

        return match ($firstPageStatus) {
            'editable' => $this->tip(self::KEY_FIRST_PAGE, 'first_page_editable', ['url' => $publicUrl]),
            'present_unverified' => $this->tip(self::KEY_FIRST_PAGE, 'first_page_unverified', ['url' => $publicUrl]),
            'missing' => $this->tip(self::KEY_FIRST_PAGE, 'first_page_missing'),
            default => null,
        };
    }

    /**
     * @param  list<string>  $selectedPackages
     * @return array{key: string, label: string, detail: string}|null
     */
    private function themeTip(InstallInputData $inputData, array $selectedPackages): ?array
    {
        $themePackage = collect($selectedPackages)
            ->first(fn (string $package): bool => str_starts_with($package, self::THEME_PACKAGE_PREFIX));

        if (! is_string($themePackage)) {
            $themeKey = $inputData->selectedThemeKey;

            if (! is_string($themeKey) || trim($themeKey) === '') {
                return null;
            }

            $themePackage = str_starts_with($themeKey, 'capell-app/')
                ? $themeKey
                : self::THEME_PACKAGE_PREFIX . trim($themeKey);
        }

        return $this->tip(self::KEY_THEME, 'theme', ['package' => $themePackage]);
    }

    /**
     * @param  list<string>  $selectedPackages
     * @return list<array{key: string, label: string, detail: string}>
     */
    private function contentPackageTips(array $selectedPackages): array
    {
        $tips = [];

        foreach (self::CONTENT_PACKAGES as $package) {
            if (count($tips) === self::MAX_PACKAGE_TIPS) {
                break;
            }

            if (in_array($package, $selectedPackages, true)) {
                $tips[] = $this->tip(
                    'package:' . $package,
                    'package_' . str_replace('-', '_', substr($package, strlen('capell-app/'))),
                );
            }
        }

        return $tips;
    }

    /**
     * @param  array<string, string>  $replace
     * @return array{key: string, label: string, detail: string}
     */
    private function tip(string $key, string $translationKey, array $replace = []): array
    {
        return [
            'key' => $key,
            'label' => __('capell-core::install.handoff.tips.' . $translationKey . '.label'),
            'detail' => __('capell-core::install.handoff.tips.' . $translationKey . '.detail', $replace),
        ];
    }
}
