<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * A short-lived, user-scoped editor recovery buffer.
 *
 * Scratch drafts are deliberately separate from Page revisions and publishing
 * workspaces. They are never read by public rendering or publication code.
 *
 * @property int $id
 * @property int $user_id
 * @property int $site_id
 * @property string $locale
 * @property string $record_type
 * @property int $record_id
 * @property string $context
 * @property array<string, mixed> $payload
 * @property string $content_hash
 * @property CarbonImmutable $saved_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<static> forEditor(Authenticatable $user, Model $record, string $locale, string $context)
 */
final class EditorScratchDraft extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'site_id',
        'locale',
        'record_type',
        'record_id',
        'context',
        'payload',
        'content_hash',
        'saved_at',
        'expires_at',
    ];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeForEditor(
        Builder $query,
        Authenticatable $user,
        Model $record,
        string $locale,
        string $context,
    ): Builder {
        return $query
            ->where('user_id', $user->getAuthIdentifier())
            ->where('site_id', $record->getAttribute('site_id'))
            ->where('locale', $locale)
            ->where('record_type', $record->getMorphClass())
            ->where('record_id', $record->getKey())
            ->where('context', $context);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'saved_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
