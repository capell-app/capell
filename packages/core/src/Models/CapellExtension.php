<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $composer_name
 * @property ?string $name
 * @property ?string $description
 * @property ?string $version
 * @property ?string $source
 * @property ExtensionStatusEnum $status
 * @property ?CarbonImmutable $enabled_at
 * @property ?CarbonImmutable $disabled_at
 * @property ?CarbonImmutable $failed_at
 * @property ?CarbonImmutable $installed_at
 * @property ?CarbonImmutable $updated_at
 * @property ?array<string, mixed> $metadata
 * @property bool $is_paid_marketplace_extension
 * @property ?string $marketplace_runtime_status
 * @property bool $marketplace_runtime_allowed
 * @property array<string, mixed>|null $marketplace_signed_activation
 * @property ?CarbonImmutable $marketplace_activation_checked_at
 * @property ?string $marketplace_runtime_reason
 */
class CapellExtension extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $table = 'capell_extensions';

    /** @var list<string> */
    protected $fillable = [
        'composer_name',
        'name',
        'description',
        'version',
        'source',
        'status',
        'enabled_at',
        'disabled_at',
        'failed_at',
        'installed_at',
        'metadata',
        'is_paid_marketplace_extension',
        'marketplace_runtime_status',
        'marketplace_runtime_allowed',
        'marketplace_signed_activation',
        'marketplace_activation_checked_at',
        'marketplace_runtime_reason',
    ];

    #[Override]
    protected static function booted(): void
    {
        static::registerModelEvent('saved', static function (): void {
            CapellCore::clearExtensionCache();
        });

        static::registerModelEvent('deleted', static function (): void {
            CapellCore::clearExtensionCache();
        });
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => ExtensionStatusEnum::class,
            'enabled_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'installed_at' => 'immutable_datetime',
            'metadata' => 'array',
            'is_paid_marketplace_extension' => 'bool',
            'marketplace_runtime_allowed' => 'bool',
            'marketplace_signed_activation' => 'array',
            'marketplace_activation_checked_at' => 'immutable_datetime',
        ];
    }
}
