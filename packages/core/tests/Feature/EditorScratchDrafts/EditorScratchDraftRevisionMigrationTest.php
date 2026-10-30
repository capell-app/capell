<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('repairs a partially applied draft revision migration without changing existing data', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.scratch_revision_migration', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    DB::setDefaultConnection('scratch_revision_migration');
    try {
        Schema::create('editor_scratch_drafts', function (Blueprint $table): void {
            $table->id();
            $table->text('payload');
            $table->uuid('revision')->nullable();
        });
        DB::table('editor_scratch_drafts')->insert(['id' => 4, 'payload' => 'retained']);
        $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_10_29_000002_add_revision_to_editor_scratch_drafts_table.php';
        $migration->up();
        $migration->up();
        expect(Schema::hasColumn('editor_scratch_drafts', 'previous_revision'))->toBeTrue()
            ->and(DB::table('editor_scratch_drafts')->where('id', 4)->value('payload'))->toBe('retained')
            ->and(DB::table('editor_scratch_drafts')->where('id', 4)->value('revision'))->toBeNull();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('scratch_revision_migration');
    }
});
