<?php

declare(strict_types=1);

namespace Capell\Installer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireInstallerBootstrap
{
    private const string SESSION_KEY = 'capell.installer.bootstrap_digest';

    public function hasValidSession(Request $request): bool
    {
        $secret = $this->validSecret();
        $digest = $request->session()->get(self::SESSION_KEY);

        return $secret !== null && is_string($digest) && hash_equals(hash('sha256', $secret), $digest);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->validSecret();
        if ($secret === null) {
            return $this->denied($request);
        }

        $digest = hash('sha256', $secret);
        $supplied = $request->input('bootstrap_secret', $request->header('X-Capell-Installer-Secret'));
        $sessionDigest = $request->session()->get(self::SESSION_KEY);
        $authorised = $supplied === null || $supplied === ''
            ? (is_string($sessionDigest) && hash_equals($digest, $sessionDigest))
            : (is_string($supplied) && hash_equals($secret, $supplied));

        if (! $authorised) {
            return $this->denied($request);
        }

        $request->session()->put(self::SESSION_KEY, $digest);
        // Do not persist the bootstrap secret in flashed validation input or install plans.
        $request->request->remove('bootstrap_secret');
        $request->query->remove('bootstrap_secret');
        if ($request->isJson()) {
            $request->json()->remove('bootstrap_secret');
        }

        return $next($request);
    }

    private function denied(Request $request): Response
    {
        if ($request->routeIs('capell-installer.unlock') && ! $request->expectsJson()) {
            return response()->view('capell-installer::bootstrap', ['bootstrapError' => true], 403)->withHeaders([
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        }

        abort(403, __('capell-installer::installer.bootstrap_required'));
    }

    private function validSecret(): ?string
    {
        $secret = config('capell-installer.bootstrap.secret');
        $expiresAt = config('capell-installer.bootstrap.expires_at');
        $timestamp = now()->getTimestamp();

        return is_string($secret) && strlen($secret) >= 32
            && is_numeric($expiresAt) && (int) $expiresAt > $timestamp
            && (int) $expiresAt <= $timestamp + 1800 ? $secret : null;
    }
}
