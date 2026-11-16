<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use PhpToken;
use Symfony\Component\Finder\Finder;

/**
 * Finds whether a package stores personal data and whether it takes part in privacy requests.
 *
 * A package stores personal data when its migrations create a column for a person's email address, phone number,
 * name, date of birth, postal code, IP address or browser, or a hash of one. It takes part in privacy requests when
 * its own source registers a Core privacy exporter and eraser, by container tag or through PrivacySubjectRegistry.
 * The scan is static because packages register from their own providers and are never all booted together.
 */
final class PersonalDataSourceScanner
{
    /** Column definitions whose name holds a person's identity or contact details; group 1 is the column name. */
    public const string PERSONAL_DATA_COLUMN_PATTERN = '/->(?:string|text|char|longText|mediumText|ipAddress|binary)\(\s*[\'"]((?:[a-z_]*_)?(?:email|phone|mobile|telephone)[a-z_]*|(?:[a-z_]*_)?(?:ip_address|user_agent|first_name|last_name|full_name|date_of_birth|postal_code|postcode)(?:_hash|_normalized)?|[a-z_]*_ip|ip)[\'"]/';

    /** Laravel's ipAddress() with no name creates an ip_address column. */
    public const string DEFAULT_IP_ADDRESS_COLUMN_PATTERN = '/->ipAddress\(\s*\)/';

    /** @var list<string> */
    private const array EXPORTER_REGISTRATIONS = ['PrivacySubjectRegistry::EXPORTER_TAG', '->registerExporter('];

    /** @var list<string> */
    private const array ERASER_REGISTRATIONS = ['PrivacySubjectRegistry::ERASER_TAG', '->registerEraser('];

    /**
     * The personal-data columns the package's migrations create, sorted.
     *
     * @return list<string>
     */
    public function personalDataColumns(string $packageRoot): array
    {
        $columns = [];

        foreach ($this->files(rtrim($packageRoot, DIRECTORY_SEPARATOR) . '/database/migrations') as $source) {
            preg_match_all(self::PERSONAL_DATA_COLUMN_PATTERN, $source, $matches);

            foreach ($matches[1] as $column) {
                $columns[$column] = $column;
            }

            if (preg_match(self::DEFAULT_IP_ADDRESS_COLUMN_PATTERN, $source) === 1) {
                $columns['ip_address'] = 'ip_address';
            }
        }

        sort($columns);

        return $columns;
    }

    public function registersExporter(string $packageRoot): bool
    {
        return $this->sourceContainsAny($packageRoot, self::EXPORTER_REGISTRATIONS);
    }

    public function registersEraser(string $packageRoot): bool
    {
        return $this->sourceContainsAny($packageRoot, self::ERASER_REGISTRATIONS);
    }

    /** @param  list<string>  $needles */
    private function sourceContainsAny(string $packageRoot, array $needles): bool
    {
        foreach ($this->files(rtrim($packageRoot, DIRECTORY_SEPARATOR) . '/src') as $source) {
            $code = $this->withoutComments($source);

            foreach ($needles as $needle) {
                if (str_contains($code, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function files(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach ((new Finder)->files()->in($directory)->name('*.php')->sortByName() as $file) {
            $files[] = $file->getContents();
        }

        return $files;
    }

    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (PhpToken::tokenize($source) as $token) {
            if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
                $code .= $token->text;
            }
        }

        return $code;
    }
}
