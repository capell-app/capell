<?php

declare(strict_types=1);

/*
 * Child process for ProcessHostAddressResolver: resolves one host with the
 * system resolver and prints its addresses as a JSON list. The parent kills
 * this process when its deadline passes, which the in-process resolver cannot
 * be made to honour.
 *
 * Arguments: <path to Composer's autoload.php> <host>
 */

use Capell\Core\Support\Http\DnsHostAddressResolver;

$autoload = $argv[1] ?? '';
$host = $argv[2] ?? '';

if (is_file($autoload) && $host !== '') {
    require $autoload;

    // An empty list on bad arguments: the parent treats it as unresolved.
    echo json_encode((new DnsHostAddressResolver)->resolve($host), JSON_THROW_ON_ERROR);
} else {
    echo '[]';
}
