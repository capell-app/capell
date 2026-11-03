<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Concerns\HasCapellTable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Override;

/**
 * A package held back from enabling and booting because an operation that
 * changed it, or a package changed alongside it, failed verification.
 *
 * @property int $id
 * @property string $composer_name
 * @property string $failing_package
 * @property list<string> $quarantined_set
 * @property string|null $failing_comparison
 * @property string|null $expected_digest
 * @property string|null $actual_digest
 * @property CarbonImmutable $quarantined_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ExtensionQuarantine extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'capell_extension_quarantines';

    /** @var list<string> */
    protected $fillable = [
        'composer_name',
        'failing_package',
        'quarantined_set',
        'failing_comparison',
        'expected_digest',
        'actual_digest',
        'quarantined_at',
    ];

    private static bool $missingTableLogged = false;

    /**
     * Quarantined package names, keyed by name. An application without the
     * table yet (before its first migration) has nothing quarantined.
     *
     * @return array<string, true>
     */
    public static function quarantinedNames(): array
    {
        $instance = new self;
        if (! Schema::connection($instance->getConnectionName())->hasTable($instance->getTable())) {
            if (! self::$missingTableLogged) {
                Log::warning('Extension quarantine table is unavailable before migration.');
                self::$missingTableLogged = true;
            }

            return [];
        }

        return array_fill_keys(self::query()->pluck('composer_name')->all(), true);
    }

    public static function isQuarantined(string $composerName): bool
    {
        return isset(self::quarantinedNames()[$composerName]);
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'quarantined_set' => 'array',
            'quarantined_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
