<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Preview;

use Illuminate\Support\Facades\Date;

class ThemePreviewSigner
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $tokenParam = '__theme_preview',
    ) {}

    public function tokenParam(): string
    {
        return $this->tokenParam;
    }

    public function generate(string $themeKey, string $presetKey, int $expiresInMinutes = 60): string
    {
        $issuedAt = Date::now()->getTimestamp();
        $payload = json_encode([
            'theme' => $themeKey,
            'preset' => $presetKey,
            'iat' => $issuedAt,
            'exp' => $issuedAt + ($expiresInMinutes * 60),
        ], JSON_THROW_ON_ERROR);

        return base64_encode($payload) . '.' . hash_hmac('sha256', $payload, $this->secretKey);
    }

    public function contextFromToken(?string $token): ThemePreviewContext
    {
        if ($token === null || $token === '') {
            return ThemePreviewContext::none();
        }

        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            return ThemePreviewContext::none();
        }

        [$encodedPayload, $signature] = $parts;
        $payload = base64_decode($encodedPayload, true);

        if ($payload === false) {
            return ThemePreviewContext::none();
        }

        if (! hash_equals(hash_hmac('sha256', $payload, $this->secretKey), $signature)) {
            return ThemePreviewContext::none();
        }

        $data = json_decode($payload, true);

        if (! is_array($data) || ($data['exp'] ?? 0) < Date::now()->getTimestamp()) {
            return ThemePreviewContext::none();
        }

        return new ThemePreviewContext(
            themeKey: is_string($data['theme'] ?? null) ? $data['theme'] : null,
            presetKey: is_string($data['preset'] ?? null) ? $data['preset'] : null,
            previewing: true,
        );
    }
}
