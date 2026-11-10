<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Data\Install\InstallHandoffFactsData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use InvalidArgumentException;

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

    /** The guide every handoff points at for creating or editing the first page. */
    public const string FIRST_PAGE_GUIDE_URL = 'https://docs.capell.app/getting-started/create-your-first-page/';

    private const string THEME_PACKAGE_PREFIX = 'capell-app/theme-';

    private const int MAX_PACKAGE_TIPS = 2;

    /**
     * Content packages that do nothing useful until an editor adds the first record,
     * mapped to their tip variant. Order is priority order: the first installed entries
     * win the two package tip slots.
     *
     * @var array<string, string>
     */
    private const array CONTENT_PACKAGES = [
        'capell-app/blog' => 'package_blog',
        'capell-app/form-builder' => 'package_form_builder',
        'capell-app/events' => 'package_events',
        'capell-app/knowledge-base' => 'package_knowledge_base',
        'capell-app/bookings' => 'package_bookings',
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
        return $this->buildFor(new InstallHandoffFactsData(
            completed: $completed,
            needsFrontendBuild: $needsFrontendBuild,
            adminUrl: $adminUrl,
            adminEmail: $adminEmail,
            publicUrl: $publicUrl,
            firstPageStatus: $firstPageStatus,
            demoContent: $inputData->demoContent,
            existingAccount: $inputData->userId !== null && ! $inputData->newUser instanceof NewUserData,
            selectedThemeKey: $inputData->selectedThemeKey,
            selectedPackages: $selectedPackages,
        ));
    }

    /**
     * The same tips from recorded facts, for surfaces that no longer hold the install input.
     *
     * @return list<array{key: string, label: string, detail: string}>
     */
    public function buildFor(InstallHandoffFactsData $facts): array
    {
        if (! $facts->completed) {
            return [$this->tip(self::KEY_REVIEW, 'review')];
        }

        $tips = [];

        if ($facts->needsFrontendBuild) {
            $tips[] = $this->tip(self::KEY_FRONTEND_BUILD, 'frontend_build', [
                'command' => 'php artisan capell:frontend-after-install --apply --no-interaction',
            ]);
        }

        $tips[] = $this->signInTip($facts);

        if ($facts->needsFrontendBuild) {
            return $tips;
        }

        $firstPage = $this->firstPageTip($facts);

        if ($firstPage !== null) {
            $tips[] = $firstPage;
        }

        $theme = $this->themeTip($facts);

        if ($theme !== null) {
            $tips[] = $theme;
        }

        return [...$tips, ...$this->contentPackageTips($facts->selectedPackages)];
    }

    /**
     * @return array{key: string, label: string, detail: string}
     */
    private function signInTip(InstallHandoffFactsData $facts): array
    {
        if ($facts->adminUrl === null) {
            return $this->tip(self::KEY_SIGN_IN, 'sign_in_no_admin');
        }

        $email = $facts->adminEmail !== null && trim($facts->adminEmail) !== '' ? trim($facts->adminEmail) : null;

        if ($email !== null) {
            return $this->tip(self::KEY_SIGN_IN, 'sign_in_as', ['url' => $facts->adminUrl, 'email' => $email]);
        }

        return $this->tip(
            self::KEY_SIGN_IN,
            $facts->existingAccount ? 'sign_in_existing' : 'sign_in',
            ['url' => $facts->adminUrl],
        );
    }

    /**
     * @return array{key: string, label: string, detail: string}|null
     */
    private function firstPageTip(InstallHandoffFactsData $facts): ?array
    {
        if ($facts->demoContent && in_array($facts->firstPageStatus, ['editable', 'present_unverified'], true)) {
            return $this->tip(self::KEY_FIRST_PAGE, 'first_page_demo', ['url' => $facts->publicUrl]);
        }

        return match ($facts->firstPageStatus) {
            'editable' => $this->tip(self::KEY_FIRST_PAGE, 'first_page_editable', ['url' => $facts->publicUrl]),
            'present_unverified' => $this->tip(self::KEY_FIRST_PAGE, 'first_page_unverified', ['url' => $facts->publicUrl]),
            'draft' => $this->tip(self::KEY_FIRST_PAGE, 'first_page_draft', ['url' => $facts->publicUrl]),
            'missing' => $this->tip(self::KEY_FIRST_PAGE, 'first_page_missing'),
            default => null,
        };
    }

    /**
     * @return array{key: string, label: string, detail: string}|null
     */
    private function themeTip(InstallHandoffFactsData $facts): ?array
    {
        $themePackage = collect($facts->selectedPackages)
            ->first(fn (string $package): bool => str_starts_with($package, self::THEME_PACKAGE_PREFIX));

        if (! is_string($themePackage)) {
            $themeKey = $facts->selectedThemeKey;

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

        foreach (self::CONTENT_PACKAGES as $package => $variant) {
            if (count($tips) === self::MAX_PACKAGE_TIPS) {
                break;
            }

            if (in_array($package, $selectedPackages, true)) {
                $tips[] = $this->tip('package:' . $package, $variant);
            }
        }

        return $tips;
    }

    /**
     * Translation keys stay literal so the language-key drift check can see every one.
     *
     * @param  array<string, string>  $replace
     * @return array{key: string, label: string, detail: string}
     */
    private function tip(string $key, string $variant, array $replace = []): array
    {
        [$label, $detail] = match ($variant) {
            'review' => [__('capell-core::install.handoff.tips.review.label'), __('capell-core::install.handoff.tips.review.detail', $replace)],
            'frontend_build' => [__('capell-core::install.handoff.tips.frontend_build.label'), __('capell-core::install.handoff.tips.frontend_build.detail', $replace)],
            'sign_in' => [__('capell-core::install.handoff.tips.sign_in.label'), __('capell-core::install.handoff.tips.sign_in.detail', $replace)],
            'sign_in_as' => [__('capell-core::install.handoff.tips.sign_in_as.label'), __('capell-core::install.handoff.tips.sign_in_as.detail', $replace)],
            'sign_in_existing' => [__('capell-core::install.handoff.tips.sign_in_existing.label'), __('capell-core::install.handoff.tips.sign_in_existing.detail', $replace)],
            'sign_in_no_admin' => [__('capell-core::install.handoff.tips.sign_in_no_admin.label'), __('capell-core::install.handoff.tips.sign_in_no_admin.detail', $replace)],
            'first_page_editable' => [__('capell-core::install.handoff.tips.first_page_editable.label'), __('capell-core::install.handoff.tips.first_page_editable.detail', $replace)],
            'first_page_unverified' => [__('capell-core::install.handoff.tips.first_page_unverified.label'), __('capell-core::install.handoff.tips.first_page_unverified.detail', $replace)],
            'first_page_draft' => [__('capell-core::install.handoff.tips.first_page_draft.label'), __('capell-core::install.handoff.tips.first_page_draft.detail', $replace)],
            'first_page_missing' => [__('capell-core::install.handoff.tips.first_page_missing.label'), __('capell-core::install.handoff.tips.first_page_missing.detail', $replace)],
            'first_page_demo' => [__('capell-core::install.handoff.tips.first_page_demo.label'), __('capell-core::install.handoff.tips.first_page_demo.detail', $replace)],
            'theme' => [__('capell-core::install.handoff.tips.theme.label'), __('capell-core::install.handoff.tips.theme.detail', $replace)],
            'package_blog' => [__('capell-core::install.handoff.tips.package_blog.label'), __('capell-core::install.handoff.tips.package_blog.detail', $replace)],
            'package_form_builder' => [__('capell-core::install.handoff.tips.package_form_builder.label'), __('capell-core::install.handoff.tips.package_form_builder.detail', $replace)],
            'package_events' => [__('capell-core::install.handoff.tips.package_events.label'), __('capell-core::install.handoff.tips.package_events.detail', $replace)],
            'package_knowledge_base' => [__('capell-core::install.handoff.tips.package_knowledge_base.label'), __('capell-core::install.handoff.tips.package_knowledge_base.detail', $replace)],
            'package_bookings' => [__('capell-core::install.handoff.tips.package_bookings.label'), __('capell-core::install.handoff.tips.package_bookings.detail', $replace)],
            default => throw new InvalidArgumentException(sprintf('Unknown install handoff tip variant [%s].', $variant)),
        };

        return ['key' => $key, 'label' => $label, 'detail' => $detail];
    }
}
