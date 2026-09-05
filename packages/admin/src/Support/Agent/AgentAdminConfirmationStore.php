<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class AgentAdminConfirmationStore
{
    /** @param array<array-key, mixed> $value */
    public static function fingerprint(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    public function put(AgentAdminToolInvocationData $invocation, array $preview): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes($this->ttlMinutes());

        Cache::put(
            $this->key($invocation->user, $token, $invocation->sessionId),
            Crypt::encryptString(json_encode([
                'record_version' => 1,
                'user_type' => $this->userType($invocation->user),
                'user_id' => (string) $invocation->user->getAuthIdentifier(),
                'session_id' => $invocation->sessionId,
                'tool' => $invocation->tool,
                'site_id' => $invocation->siteId,
                'payload_hash' => self::fingerprint($invocation->payload),
                'payload' => $invocation->payload,
                'preview_fingerprint' => self::fingerprint($preview),
                'preview' => $preview,
                'expires_at' => $expiresAt->toIso8601String(),
            ], JSON_THROW_ON_ERROR)),
            $expiresAt,
        );

        return $token;
    }

    /**
     * @return array{record_version: int, user_type: string, user_id: string, session_id: ?string, tool: string, site_id: int, payload_hash: string, payload: array<string, mixed>, preview_fingerprint: string, preview: array<string, mixed>, expires_at: string}
     */
    public function pull(string $token, Authenticatable $user, string $tool, int $siteId, ?string $sessionId = null): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            $this->rejectInvalidConfirmation();
        }

        try {
            $encrypted = Cache::lock($this->lockKey($user, $token, $sessionId), 5)->block(
                5,
                fn (): mixed => Cache::pull($this->key($user, $token, $sessionId)),
            );
            $record = is_string($encrypted)
                ? json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR)
                : null;
        } catch (Throwable) {
            $record = null;
        }

        if (! is_array($record)
            || ! is_int($record['record_version'] ?? null)
            || ! is_string($record['user_type'] ?? null)
            || ! is_string($record['user_id'] ?? null)
            || ! is_string($record['session_id'] ?? null) && $record['session_id'] !== null
            || ! is_string($record['tool'] ?? null)
            || ! is_int($record['site_id'] ?? null)
            || $record['record_version'] !== 1
            || $record['user_type'] !== $this->userType($user)
            || $record['user_id'] !== (string) $user->getAuthIdentifier()
            || $record['session_id'] !== $sessionId
            || $record['tool'] !== $tool
            || $record['site_id'] !== $siteId
            || ! is_string($record['payload_hash'] ?? null)
            || ! is_array($record['payload'] ?? null)
            || ! hash_equals($record['payload_hash'], self::fingerprint($record['payload']))
            || ! is_string($record['preview_fingerprint'] ?? null)
            || ! is_array($record['preview'] ?? null)
            || ! is_string($record['expires_at'] ?? null)) {
            $this->rejectInvalidConfirmation();
        }

        try {
            $expiresAt = CarbonImmutable::parse($record['expires_at']);
        } catch (Throwable) {
            $this->rejectInvalidConfirmation();
        }

        if (now()->greaterThanOrEqualTo($expiresAt)) {
            $this->rejectInvalidConfirmation();
        }

        return [
            'record_version' => $record['record_version'],
            'user_type' => $record['user_type'],
            'user_id' => $record['user_id'],
            'session_id' => $record['session_id'],
            'tool' => $record['tool'],
            'site_id' => $record['site_id'],
            'payload_hash' => $record['payload_hash'],
            'payload' => $record['payload'],
            'preview_fingerprint' => $record['preview_fingerprint'],
            'preview' => $record['preview'],
            'expires_at' => $record['expires_at'],
        ];
    }

    private function key(Authenticatable $user, string $token, ?string $sessionId = null): string
    {
        return 'capell-admin-agent-confirmation:' . hash('sha256', implode('|', [
            $token,
            $this->userType($user),
            (string) $user->getAuthIdentifier(),
            $sessionId ?? '',
        ]));
    }

    private function lockKey(Authenticatable $user, string $token, ?string $sessionId = null): string
    {
        return $this->key($user, $token, $sessionId) . ':lock';
    }

    private function userType(Authenticatable $user): string
    {
        return method_exists($user, 'getMorphClass') ? $user->getMorphClass() : $user::class;
    }

    private function rejectInvalidConfirmation(): never
    {
        throw new AuthorizationException((string) __('capell-admin::agent.confirmation_invalid'));
    }

    private function ttlMinutes(): int
    {
        $value = config('capell-admin.agent_confirmation_ttl_minutes', 10);

        return is_int($value) ? max(1, min(60, $value)) : 10;
    }
}
