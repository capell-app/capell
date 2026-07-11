<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\Filesystem;

final class LockdownStore
{
    /** @var array<string, mixed>|null */
    private ?array $cachedData = null;

    public function __construct(private readonly Filesystem $files) {}

    /**
     * @param  array<string, mixed>|null  $staticCacheState
     * @return array<string, mixed>
     */
    public function activateFor(Authenticatable $user, ?array $staticCacheState = null): array
    {
        $payload = [
            'active' => true,
            'activated_at' => CarbonImmutable::now()->toIso8601String(),
            'activated_by' => [
                'id' => $this->userId($user),
                'email' => $this->userEmail($user),
                'name' => $this->userName($user),
            ],
            'allowed_user_ids' => $this->allowedUserIds($user),
            'allowed_emails' => $this->configuredBreakGlassEmails(),
            'static_cache' => $staticCacheState,
        ];

        $this->write($payload);

        return $payload;
    }

    public function deactivate(): void
    {
        if ($this->files->exists($this->path())) {
            $this->files->delete($this->path());
        }

        $this->cachedData = [];
    }

    public function active(): bool
    {
        return ($this->data()['active'] ?? false) === true;
    }

    public function canAccessAdmin(?Authenticatable $user): bool
    {
        if (! $this->active()) {
            return true;
        }

        if (! $user instanceof Authenticatable) {
            return false;
        }

        $data = $this->data();
        $userId = $this->userId($user);
        $userEmail = $this->userEmail($user);

        return in_array($userId, $this->stringList($data['allowed_user_ids'] ?? []), true)
            || ($userEmail !== null && in_array($userEmail, $this->stringList($data['allowed_emails'] ?? []), true));
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        if ($this->cachedData !== null) {
            return $this->cachedData;
        }

        if (! $this->files->exists($this->path())) {
            return $this->cachedData = [];
        }

        $contents = $this->files->get($this->path());
        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ($decoded['active'] ?? null) !== true) {
            return $this->cachedData = ['active' => true, 'invalid' => true];
        }

        return $this->cachedData = $decoded;
    }

    public function path(): string
    {
        $configured = config('capell.lockdown.file');

        return is_string($configured) && $configured !== ''
            ? $configured
            : storage_path('framework/capell-lockdown.json');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(array $payload): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! $this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }

        $this->files->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->cachedData = $payload;
    }

    /**
     * @return list<string>
     */
    private function allowedUserIds(Authenticatable $user): array
    {
        return array_values(array_unique([
            $this->userId($user),
            ...$this->stringList(config('capell.lockdown.break_glass_user_ids', [])),
        ]));
    }

    /**
     * @return list<string>
     */
    private function configuredBreakGlassEmails(): array
    {
        return array_values(array_unique(array_map(
            strtolower(...),
            $this->stringList(config('capell.lockdown.break_glass_emails', [])),
        )));
    }

    private function userId(Authenticatable $user): string
    {
        return (string) $user->getAuthIdentifier();
    }

    private function userEmail(Authenticatable $user): ?string
    {
        $email = data_get($user, 'email');

        return is_string($email) && $email !== '' ? strtolower($email) : null;
    }

    private function userName(Authenticatable $user): ?string
    {
        $name = data_get($user, 'name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(collect($value)
            ->filter(fn (mixed $item): bool => is_scalar($item))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all());
    }
}
