<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Concerns\HasCapellTable;
use Capell\Core\Enums\IdempotencyKeyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $key
 * @property string $payload_hash
 * @property IdempotencyKeyStatus $status
 * @property array<string, mixed>|null $result
 * @property string|null $claim_token
 * @property CarbonImmutable|null $expires_at null for a completed result kept permanently
 */
final class IdempotencyKey extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use MassPrunable;

    protected $table = 'capell_idempotency_keys';

    /** @var list<string> */
    protected $fillable = [
        'key',
        'payload_hash',
        'status',
        'claim_token',
        'result',
        'expires_at',
    ];

    /** @var list<string> */
    protected $hidden = ['result', 'claim_token'];

    public function hasExpired(): bool
    {
        return $this->expires_at instanceof CarbonImmutable && $this->expires_at->isPast();
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->whereNotNull('expires_at')->where('expires_at', '<', CarbonImmutable::now());
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => IdempotencyKeyStatus::class,
            'result' => 'encrypted:array',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
