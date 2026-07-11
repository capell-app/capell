<?php

declare(strict_types=1);

namespace Capell\Core\Observers;

use Capell\Core\Actions\ClearGeneratedThemeImageAction;
use Capell\Core\Actions\GenerateThemeImageAction;
use Capell\Core\Actions\GenerateUniqueKeyAction;
use Capell\Core\Actions\InvalidateGeneratedThemeImageAction;
use Capell\Core\Actions\UpdateTailwindClassesFileAction;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Events\ThemeColorsUpdated;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\CapellCoreHelper;

class ThemeObserver
{
    public function creating(Theme $theme): void
    {
        if ($theme->key === 'default' && ! array_key_exists('default', $theme->getAttributes())) {
            $theme->default = ! Theme::query()->default()->exists();
        }
    }

    public function saving(Theme $theme): void
    {
        $key = $theme->getAttribute('key');

        if (! is_string($key) || $key === '') {
            $theme->key = GenerateUniqueKeyAction::run($theme);
            $key = $theme->key;
        }

        $theme->active_key = $theme->deleted_at === null ? $key : null;
    }

    public function saved(Theme $theme): void
    {
        CapellCoreHelper::flushCache([
            CacheEnum::HasFoundationTheme,
            CacheEnum::Site,
            CacheEnum::RelationExists,
        ]);
        $this->purgeFrontendCdnCache($theme);

        $mainClass = $theme->getMeta('main_class');
        if (is_string($mainClass) && $mainClass !== '') {
            UpdateTailwindClassesFileAction::run([$mainClass]);
        }

        $this->queueGeneratedImage($theme);
    }

    public function created(Theme $theme): void
    {
        if ($theme->colors !== []) {
            event(new ThemeColorsUpdated($theme));
        }
    }

    public function updated(Theme $theme): void
    {
        if ($theme->colorsHaveChanged()) {
            event(new ThemeColorsUpdated($theme));
        }
    }

    public function deleted(Theme $theme): void
    {
        if ($theme->active_key !== null) {
            $theme->forceFill(['active_key' => null])->saveQuietly();
        }

        $this->saved($theme);
    }

    public function restored(Theme $theme): void
    {
        $theme->forceFill(['active_key' => $theme->key])->saveQuietly();

        $this->saved($theme);
    }

    private function queueGeneratedImage(Theme $theme): void
    {
        if ($theme->hasManualAdminImage()) {
            ClearGeneratedThemeImageAction::run($theme);

            return;
        }

        $admin = is_array($theme->admin) ? $theme->admin : [];
        $signature = $theme->generatedImageSignature();

        if (($admin['generated_image_signature'] ?? null) === $signature
            && in_array($admin['generated_image_status'] ?? null, ['pending', 'ready'], true)) {
            return;
        }

        InvalidateGeneratedThemeImageAction::run($theme, $signature);
        GenerateThemeImageAction::dispatch((int) $theme->getKey(), $signature);
    }

    private function purgeFrontendCdnCache(Theme $theme): void
    {
        $query = Site::query();

        if (! $theme->default) {
            $query->where('theme_id', $theme->getKey());
        }

        $siteIds = $query
            ->pluck('id')
            ->map(fn (int $siteId): string => 'site-' . $siteId)
            ->all();

        event(new FrontendSurrogateKeysInvalidated($siteIds));
    }
}
