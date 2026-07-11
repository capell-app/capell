<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Models\Media;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class ConfigureMailMarkdownLogoAction
{
    use AsObject;

    public function handle(): void
    {
        if (filled(config('mail.markdown.logo'))) {
            return;
        }

        if (! $this->hasRequiredTables()) {
            return;
        }

        $site = Site::query()
            ->with('logo')
            ->default()
            ->first();

        if (! $site instanceof Site) {
            return;
        }

        // Branded email logo is on by default; a site opts out via its
        // meta flag `mail.use_site_logo` => false.
        if (! (bool) data_get($site->meta ?? [], 'mail.use_site_logo', true)) {
            return;
        }

        $logo = $site->logo;

        // Prefer the site's uploaded logo; otherwise fall back to the bundled
        // Capell brand mark so emails are branded out of the box. Only when
        // neither exists does the header fall back to the site title text.
        $logoUrl = $logo instanceof MediaContract
            ? $logo->getFullUrl()
            : $this->defaultLogoUrl();

        if ($logoUrl === null) {
            return;
        }

        config(['mail.markdown.logo' => $logoUrl]);
    }

    private function defaultLogoUrl(): ?string
    {
        $path = public_path('capell-icon-512x512.png');

        return is_file($path) ? asset('capell-icon-512x512.png') : null;
    }

    private function hasRequiredTables(): bool
    {
        try {
            return Schema::hasTable((new Site)->getTable())
                && Schema::hasTable((new Media)->getTable())
                && Schema::hasColumn((new Media)->getTable(), 'deleted_at');
        } catch (Throwable) {
            return false;
        }
    }
}
