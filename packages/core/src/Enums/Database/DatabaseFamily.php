<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Database;

/** @experimental */
enum DatabaseFamily: string
{
    case MySql = 'mysql';
    case MariaDb = 'mariadb';
    case Sqlite = 'sqlite';
    case PostgreSql = 'pgsql';
}
