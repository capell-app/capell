<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/activitylog-bootstrap.php';

$root = $argv[1];
$fixture = bootActivityLogFixture($root);
$app = $fixture['app'];
$source = $fixture['source'];

// The legacy vendor create migration declares a named class. Give each ordering
// scenario its own process instead of redeclaring that class in a shared suite.
$directory = sys_get_temp_dir() . '/capell-activity-migrations-' . uniqid();
mkdir($directory);
$name = '2026_10_05_000001_add_attribute_changes_to_activity_log_table';
$corePath = $directory . '/' . $name . '.php';
$vendorPath = $directory . '/' . $argv[2] . '_create_activity_log_table.php';
copy($root . '/packages/core/database/migrations/' . $name . '.php', $corePath);
$repository = new DatabaseMigrationRepository($app->make(ConnectionResolverInterface::class), 'migrations');
$repository->createRepository();
$migrator = new Migrator($repository, $app->make(ConnectionResolverInterface::class), new Filesystem, $app->make(Dispatcher::class));

try {
    $migrator->run([$directory]);
    $recordedBeforeVendor = in_array($name, $repository->getRan(), true);
    $tableBeforeVendor = Schema::hasTable('activity_log');
    copy($source . '/database/migrations/create_activity_log_table.php.stub', $vendorPath);
    $migrator->run([$directory]);
    $migrator->run([$directory]);

    echo json_encode([
        'recorded_before_vendor' => $recordedBeforeVendor,
        'table_before_vendor' => $tableBeforeVendor,
        'recorded_after_vendor' => in_array($name, $repository->getRan(), true),
        'column_after_vendor' => Schema::hasColumn('activity_log', 'attribute_changes'),
        'recorded_count' => array_count_values($repository->getRan())[$name] ?? 0,
    ], JSON_THROW_ON_ERROR);
} finally {
    unlink($corePath);
    if (is_file($vendorPath)) {
        unlink($vendorPath);
    }

    rmdir($directory);
}
