<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Frontend\Support\Logging\FrontendLogger;
use Capell\Frontend\Support\Render\PublicViewQueryGuard;
use Pest\Arch\Repositories\ObjectsRepository;
use Pest\Arch\Support\Composer;
use Saade\FilamentAdjacencyList\Forms\Components\Concerns\HasRelationship;

it('keeps forbidden PHP and debug functions out of Core packages', function (): void {
    // Keep this list aligned with Pest's PHP preset when upgrading Pest. Core permits var_export.
    $phpSymbols = [
        'debug_zval_dump',
        'debug_backtrace',
        'debug_print_backtrace',
        'dump',
        'ray',
        'ds',
        'die',
        'goto',
        'global',
        'var_dump',
        'phpinfo',
        'echo',
        'ereg',
        'eregi',
        'mysql_connect',
        'mysql_pconnect',
        'mysql_query',
        'mysql_select_db',
        'mysql_fetch_array',
        'mysql_fetch_assoc',
        'mysql_fetch_object',
        'mysql_fetch_row',
        'mysql_num_rows',
        'mysql_affected_rows',
        'mysql_free_result',
        'mysql_insert_id',
        'mysql_error',
        'mysql_real_escape_string',
        'print',
        'print_r',
        'xdebug_break',
        'xdebug_call_class',
        'xdebug_call_file',
        'xdebug_call_int',
        'xdebug_call_line',
        'xdebug_code_coverage_started',
        'xdebug_connect_to_client',
        'xdebug_debug_zval',
        'xdebug_debug_zval_stdout',
        'xdebug_dump_superglobals',
        'xdebug_get_code_coverage',
        'xdebug_get_collected_errors',
        'xdebug_get_function_count',
        'xdebug_get_function_stack',
        'xdebug_get_gc_run_count',
        'xdebug_get_gc_total_collected_roots',
        'xdebug_get_gcstats_filename',
        'xdebug_get_headers',
        'xdebug_get_monitored_functions',
        'xdebug_get_profiler_filename',
        'xdebug_get_stack_depth',
        'xdebug_get_tracefile_name',
        'xdebug_info',
        'xdebug_is_debugger_active',
        'xdebug_memory_usage',
        'xdebug_notify',
        'xdebug_peak_memory_usage',
        'xdebug_print_function_stack',
        'xdebug_set_filter',
        'xdebug_start_code_coverage',
        'xdebug_start_error_collection',
        'xdebug_start_function_monitor',
        'xdebug_start_gcstats',
        'xdebug_start_trace',
        'xdebug_stop_code_coverage',
        'xdebug_stop_error_collection',
        'xdebug_stop_function_monitor',
        'xdebug_stop_gcstats',
        'xdebug_stop_trace',
        'xdebug_time_index',
        'xdebug_var_dump',
        'trap',
    ];
    $debugSymbols = ['dd', 'dump', 'print_r', 'die', 'ray', 'rd', 'var_dump', 'exit', 'env', 'sleep', 'usleep'];
    $phpExempt = [
        FrontendLogger::class,
        PublicViewQueryGuard::class,
        // A contribution is attributed to the provider currently booting via the call stack.
        ExtensionContributionReceiptRegistry::class,
    ];
    $repository = ObjectsRepository::getInstance();

    // Resolve symbols with Pest's own parser, then check each source object's uses in one pass.
    $resolve = static function (array $symbols) use ($repository): array {
        $names = [];

        foreach ($symbols as $symbol) {
            foreach ($repository->allByNamespace($symbol) as $dependency) {
                if (str_starts_with($dependency->name, $symbol)) {
                    $names[$dependency->name] = true;
                }
            }
        }

        return $names;
    };
    $phpRestrictions = $resolve($phpSymbols);
    $debugRestrictions = $resolve($debugSymbols);
    $violations = [];

    foreach (array_unique(Composer::userNamespaces()) as $namespace) {
        foreach ($repository->allByNamespace($namespace, false) as $object) {
            if (! str_starts_with($object->name, $namespace)) {
                continue;
            }

            $phpAllowed = array_any($phpExempt, static fn (string $class): bool => str_starts_with($object->name, $class));
            $debugAllowed = str_starts_with($object->name, EditPage::class);

            foreach ($object->uses as $dependency) {
                if ((isset($phpRestrictions[$dependency]) && ! $phpAllowed)
                    || (isset($debugRestrictions[$dependency]) && ! $debugAllowed)) {
                    $violations[] = sprintf('%s uses %s in %s', $object->name, $dependency, $object->path);
                }
            }
        }
    }

    expect(array_values(array_unique($violations)))->toBe([]);
});

arch()->preset()->laravel();

arch()->preset()->security()->ignoring([
    HasRelationship::class,
]);

arch()
    ->expect([
        'Capell\Core',
    ])
    ->classes()
    ->toUseStrictEquality()
    /*->toHavePropertiesDocumented()
    ->toHaveMethodsDocumented()*/;
