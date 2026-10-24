<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

it('serialises competing domain saves and restores before either claim is persisted', function (?string $scheme, ?string $host, bool $restore): void {
    $database = tempnam(sys_get_temp_dir(), 'capell-domain-claims-');
    throw_unless(is_string($database), RuntimeException::class);
    foreach (['claim_first', 'claim_second'] as $connection) {
        config(['database.connections.' . $connection => [
            'driver' => 'sqlite', 'database' => $database, 'prefix' => '',
            'foreign_key_constraints' => false, 'busy_timeout' => 0,
        ]]);
    }

    try {
        $schema = Schema::connection('claim_first');
        $schema->create('site_domain_claim_locks', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        DB::connection('claim_first')->table('site_domain_claim_locks')->insert(['id' => 1]);
        $schema->create('sites', function (Blueprint $table): void {
            $table->id();
            $table->softDeletes();
        });
        DB::connection('claim_first')->table('sites')->insert([['id' => 1], ['id' => 2]]);
        $schema->create('site_domains', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('site_id');
            $table->unsignedInteger('language_id')->nullable();
            $table->string('scheme')->nullable();
            $table->string('domain')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('path')->nullable();
            $table->string('routing_identity')->nullable()->unique();
            $table->boolean('default')->default(false);
            $table->boolean('status')->default(true);
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->unsignedInteger('deleted_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        $firstActor = User::factory()->createOne();
        $firstActor->assignedSiteIds = collect([1]);
        $secondActor = User::factory()->createOne();
        $secondActor->assignedSiteIds = collect([2]);
        test()->actingAs($firstActor);

        // Only suppress post-save cache/translation side effects in this isolated database.
        Event::fake(['eloquent.saved: ' . SiteDomain::class]);
        $first = new SiteDomain(['site_id' => 1, 'scheme' => $scheme, 'domain' => $host, 'path' => '/a', 'default' => false]);
        $first->setConnection('claim_first');
        $second = new SiteDomain(['site_id' => 2, 'scheme' => 'https', 'domain' => 'owned.test', 'path' => '/b', 'default' => false]);
        $second->setConnection('claim_second');
        if ($restore) {
            $id = DB::connection('claim_second')->table('site_domains')->insertGetId([
                ...$second->getAttributes(), 'deleted_at' => now(),
            ]);
            $second = SiteDomain::on('claim_second')->withTrashed()->findOrFail($id);
        }

        $attempted = false;
        // Pause A after its observer's check and before INSERT. B uses a separate
        // connection to the same file and must be blocked even though no claim exists.
        Event::listen('eloquent.creating: ' . SiteDomain::class, function (SiteDomain $domain) use ($first, $second, $firstActor, $secondActor, $restore, &$attempted): void {
            if ($domain !== $first) {
                return;
            }

            $attempted = true;
            test()->actingAs($secondActor);
            try {
                expect(fn () => $restore ? $second->restore() : $second->save())
                    ->toThrow(QueryException::class, 'database is locked');
            } finally {
                test()->actingAs($firstActor);
            }
        });

        expect($first->save())->toBeTrue()->and($attempted)->toBeTrue();
        test()->actingAs($secondActor);
        // Once A commits, B sees the claim and receives the normal refusal.
        expect(fn () => $restore ? $second->restore() : $second->save())->toThrow(ValidationException::class);
        expect(SiteDomain::on('claim_first')->count())->toBe(1);
        if ($restore) {
            expect(expectPresent($second->fresh())->trashed())->toBeTrue();
        }
    } finally {
        DB::purge('claim_first');
        DB::purge('claim_second');
        unlink($database);
    }
})->with([
    ['https', 'owned.test'],
    [null, 'owned.test'],
    ['https', null],
    [null, null],
])->with([false, true]);
