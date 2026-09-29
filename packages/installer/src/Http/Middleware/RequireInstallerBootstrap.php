<?php

declare(strict_types=1);

namespace Capell\Installer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireInstallerBootstrap
{
    private const string SESSION_KEY = 'capell.installer.bootstrap_digest';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('capell-installer.bootstrap.secret');
        $expiresAt = config('capell-installer.bootstrap.expires_at');
        $timestamp = now()->getTimestamp();

        abort_unless(is_string($secret) && strlen($secret) >= 32
            && is_numeric($expiresAt) && (int) $expiresAt > $timestamp
            && (int) $expiresAt <= $timestamp + 1800, 403, __('capell-installer::installer.bootstrap_required'));

        $digest = hash('sha256', (string) $secret);
        $supplied = $request->input('bootstrap_secret', $request->header('X-Capell-Installer-Secret'));
        $sessionDigest = $request->session()->get(self::SESSION_KEY);
        $authorised = $supplied === null || $supplied === ''
            ? (is_string($sessionDigest) && hash_equals($digest, $sessionDigest))
            : (is_string($supplied) && hash_equals($secret, $supplied));

        abort_unless($authorised, 403, __('capell-installer::installer.bootstrap_required'));

        $request->session()->put(self::SESSION_KEY, $digest);
        // Do not persist the bootstrap secret in flashed validation input or install plans.
        $request->request->remove('bootstrap_secret');
        if ($request->isJson()) {
            $request->json()->remove('bootstrap_secret');
        }

        return $next($request);
    }
}
