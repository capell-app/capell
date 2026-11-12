<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Security;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Pboivin\FilamentPeek\CachedPreview;
use Throwable;

/**
 * Grants for Filament Peek's internal preview URL. Each preview gets its own random token, bound to the
 * signed-in user and the panel that rendered it, and expiring with the cached preview it names. Opening the
 * URL needs that same user signed in to that panel, still allowed into it, before the grant expires.
 */
final class PeekPreviewGrants
{
    private const string KEY_PREFIX = 'capell-admin:peek-preview-grant:';

    private const string PURPOSE = 'filament-peek.preview';

    public function issue(): string
    {
        $panel = $this->currentPanel();
        $user = $this->panelUser($panel);

        abort_unless($panel instanceof Panel && $user instanceof Authenticatable, 403);

        $token = Str::random(64);
        $ttl = max(1, CachedPreview::$cacheDuration);

        Cache::store(CachedPreview::$cacheStore)->put($this->key($token), [
            'purpose' => self::PURPOSE,
            'token' => hash('sha256', $token),
            'panel' => $panel->getId(),
            'user' => (string) $user->getAuthIdentifier(),
            'expires_at' => now()->addSeconds($ttl)->getTimestamp(),
        ], $ttl);

        return $token;
    }

    /** Whether the current request may open the preview the token names. */
    public function allows(string $token): bool
    {
        if ($token === '' || strlen($token) > 255) {
            return false;
        }

        $grant = Cache::store(CachedPreview::$cacheStore)->get($this->key($token));

        if (! is_array($grant)
            || ($grant['purpose'] ?? null) !== self::PURPOSE
            || ! is_string($grant['token'] ?? null)
            || ! hash_equals($grant['token'], hash('sha256', $token))
            || ! is_int($grant['expires_at'] ?? null)
            || $grant['expires_at'] <= now()->getTimestamp()
            || ! is_string($grant['panel'] ?? null)
            || ! is_string($grant['user'] ?? null)) {
            return false;
        }

        try {
            $panel = Filament::getPanel($grant['panel'], isStrict: true);
        } catch (Throwable) {
            return false;
        }

        $user = $this->panelUser($panel);

        if (! $user instanceof Authenticatable || ! hash_equals($grant['user'], (string) $user->getAuthIdentifier())) {
            return false;
        }

        return $user instanceof FilamentUser && $user->canAccessPanel($panel);
    }

    private function key(string $token): string
    {
        return self::KEY_PREFIX . hash('sha256', $token);
    }

    private function currentPanel(): ?Panel
    {
        try {
            return Filament::getCurrentPanel() ?? Filament::getDefaultPanel();
        } catch (Throwable) {
            return null;
        }
    }

    private function panelUser(?Panel $panel): ?Authenticatable
    {
        if (! $panel instanceof Panel) {
            return null;
        }

        $user = $panel->auth()->user();

        return $user instanceof Authenticatable ? $user : null;
    }
}
