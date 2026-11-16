<?php

declare(strict_types=1);

use Capell\Core\Support\Security\PersonalDataSourceScanner;
use Capell\Core\Testing\Assertions\AssertsPrivacyContributors;

/** A throwaway package whose migration defines the given column and whose provider holds the given source. */
function privacyContractPackage(?string $column, string $source = ''): string
{
    $root = sys_get_temp_dir() . '/capell-privacy-contract-' . bin2hex(random_bytes(6));
    mkdir($root . '/database/migrations', 0777, true);
    mkdir($root . '/src', 0777, true);

    if ($column !== null) {
        $definition = str_starts_with($column, '->') ? '$table' . $column : '$table->string(\'' . $column . "')";
        file_put_contents($root . '/database/migrations/2026_01_01_000000_create_people.php', '<?php ' . $definition . '->nullable();');
    }

    file_put_contents($root . '/src/Provider.php', "<?php\n" . $source);

    return $root;
}

it('finds personal-data columns, including hashed forms and the default IP address column', function (string $column, string $expected): void {
    expect((new PersonalDataSourceScanner)->personalDataColumns(privacyContractPackage($column)))->toBe([$expected]);
})->with([
    'email' => ['email', 'email'],
    'prefixed phone' => ['customer_phone', 'customer_phone'],
    'mobile' => ['mobile', 'mobile'],
    'telephone' => ['contact_telephone', 'contact_telephone'],
    'hashed browser' => ['visitor_user_agent_hash', 'visitor_user_agent_hash'],
    'date of birth' => ['date_of_birth', 'date_of_birth'],
    'postcode' => ['postcode', 'postcode'],
    'named ip' => ['last_login_ip', 'last_login_ip'],
    'default ip' => ['->ipAddress()', 'ip_address'],
]);

it('ignores columns that hold no personal data', function (): void {
    expect((new PersonalDataSourceScanner)->personalDataColumns(privacyContractPackage('title')))->toBe([]);
});

it('passes a package that stores personal data and registers both contributors', function (string $source): void {
    AssertsPrivacyContributors::run(privacyContractPackage('email', $source));

    expect(true)->toBeTrue();
})->with([
    'container tags' => ['$this->app->tag([Exporter::class], PrivacySubjectRegistry::EXPORTER_TAG); $this->app->tag([Eraser::class], PrivacySubjectRegistry::ERASER_TAG);'],
    'registry calls' => ['$registry->registerExporter(new Exporter)->registerEraser(new Eraser);'],
]);

it('passes a package that stores no personal data and registers nothing', function (): void {
    AssertsPrivacyContributors::run(privacyContractPackage('title'));
    AssertsPrivacyContributors::run(privacyContractPackage(null));

    expect(true)->toBeTrue();
});

it('fails a package that stores personal data without both contributors', function (string $source): void {
    expect(fn () => AssertsPrivacyContributors::run(privacyContractPackage('first_name', $source)))
        ->toThrow(AssertionError::class, 'stores personal data (first_name) but does not register both a privacy exporter and an eraser');
})->with([
    'nothing registered' => [''],
    'export only' => ['PrivacySubjectRegistry::EXPORTER_TAG;'],
    'contract names only' => ['final class Contributor implements PrivacySubjectExporter, PrivacySubjectEraser {}'],
    'registrations only in comments' => ["/* PrivacySubjectRegistry::EXPORTER_TAG */\n// PrivacySubjectRegistry::ERASER_TAG"],
]);

it('fails a package that registers only one side even without personal-data columns', function (string $source, string $message): void {
    expect(fn () => AssertsPrivacyContributors::run(privacyContractPackage('title', $source)))
        ->toThrow(AssertionError::class, $message);
})->with([
    'eraser only' => ['$registry->registerEraser(new Eraser);', 'registers a privacy eraser but no exporter'],
    'exporter only' => ['$registry->registerExporter(new Exporter);', 'registers a privacy exporter but no eraser'],
]);
