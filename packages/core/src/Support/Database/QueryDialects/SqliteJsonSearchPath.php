<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\QueryDialects;

final readonly class SqliteJsonSearchPath
{
    /**
     * @param  list<string>  $collectionPaths
     */
    private function __construct(
        public array $collectionPaths,
        public string $valuePath,
    ) {}

    public static function parse(string $path): ?self
    {
        if (! str_starts_with($path, '$')) {
            return null;
        }

        $collectionPaths = [];
        $pendingPath = '$';
        $offset = 1;

        while ($offset < strlen($path)) {
            if (preg_match('/\G(?:\.\*|\[\*\])/', $path, $wildcard, 0, $offset) === 1) {
                $collectionPaths[] = $pendingPath;
                $pendingPath = '$';
                $offset += strlen($wildcard[0]);

                continue;
            }

            if (preg_match('/\G\.([A-Za-z_]\w*)/', $path, $member, 0, $offset) !== 1) {
                return null;
            }

            $pendingPath .= '.' . $member[1];
            $offset += strlen($member[0]);
        }

        if ($collectionPaths === [] || $pendingPath === '$') {
            return null;
        }

        return new self($collectionPaths, $pendingPath);
    }
}
