<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Capell\Core\Support\Privacy\PrivacySubjectRegistry;
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

    /**
     * The personal-data columns the package's migrations create, sorted.
     *
     * @return list<string>
     */
    public function personalDataColumns(string $packageRoot): array
    {
        $columns = [];

        foreach ($this->files(rtrim($packageRoot, DIRECTORY_SEPARATOR) . '/database/migrations') as $migration) {
            $source = $this->withoutComments($migration);
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
        return $this->registers($packageRoot, 'EXPORTER_TAG', PrivacySubjectRegistry::EXPORTER_TAG, 'registerExporter');
    }

    public function registersEraser(string $packageRoot): bool
    {
        return $this->registers($packageRoot, 'ERASER_TAG', PrivacySubjectRegistry::ERASER_TAG, 'registerEraser');
    }

    /**
     * Whether the package's code (not its comments or strings) registers a contributor: the registry's tag constant,
     * named directly, fully qualified or through an alias, the tag's literal value, or the registry method.
     */
    private function registers(string $packageRoot, string $tagConstant, string $tagValue, string $method): bool
    {
        foreach ($this->files(rtrim($packageRoot, DIRECTORY_SEPARATOR) . '/src') as $source) {
            $code = $this->withoutComments($source);
            $registryNames = ['privacysubjectregistry' => true];

            if (preg_match_all('/\bPrivacySubjectRegistry\s+as\s+(\w+)/i', $code, $aliases) > 0) {
                foreach ($aliases[1] as $alias) {
                    $registryNames[strtolower($alias)] = true;
                }
            }

            $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn (PhpToken $token): bool => ! $token->isIgnorable()));

            foreach ($tokens as $index => $token) {
                if ($token->is(T_CONSTANT_ENCAPSED_STRING) && trim($token->text, '\'"') === $tagValue) {
                    return true;
                }

                if ($token->is(T_STRING) && $token->text === $method && ($tokens[$index - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) === true && ($tokens[$index + 1] ?? null)?->is('(') === true) {
                    return true;
                }

                if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                    && isset($registryNames[strtolower(substr(strrchr('\\' . $token->text, '\\') ?: '', 1))])
                    && ($tokens[$index + 1] ?? null)?->is(T_DOUBLE_COLON) === true
                    && ($tokens[$index + 2]->text ?? null) === $tagConstant) {
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
