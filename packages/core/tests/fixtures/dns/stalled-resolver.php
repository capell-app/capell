<?php

declare(strict_types=1);

// Stands in for a lookup against a DNS server that never answers in time: it
// answers with a public address, but only after the caller's deadline. Runs
// without an autoloader, so it uses only core PHP functions.
time_nanosleep(20, 0);

echo json_encode(['93.184.216.34']);
