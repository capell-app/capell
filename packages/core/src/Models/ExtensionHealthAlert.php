<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Enums\ExtensionHealthAlertCategory;
use Capell\Core\Enums\ExtensionHealthAlertSeverity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $alert_id
 * @property string $source
 * @property string|null $extension_slug
 * @property string|null $composer_name
 * @property string|null $affected_site_id
 * @property string|null $affected_install_id
 * @property ExtensionHealthAlertSeverity $severity
 * @property ExtensionHealthAlertCategory $category
 * @property string $title
 * @property string $message
 * @property string|null $required_action
 * @property bool $runtime_disabled
 * @property bool $protected_actions_blocked
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $expires_at
 * @property string $signature
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ExtensionHealthAlert extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'capell_extension_health_alerts';

    /** @var list<string> */
    protected $fillable = [
        'alert_id',
        'source',
        'extension_slug',
        'composer_name',
        'affected_site_id',
        'affected_install_id',
        'severity',
        'category',
        'title',
        'message',
        'required_action',
        'runtime_disabled',
        'protected_actions_blocked',
        'issued_at',
        'expires_at',
        'signature',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'severity' => ExtensionHealthAlertSeverity::class,
            'category' => ExtensionHealthAlertCategory::class,
            'runtime_disabled' => 'bool',
            'protected_actions_blocked' => 'bool',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
