<?php

declare(strict_types=1);

use Illuminate\Support\Str;

$token = Str::upper('blaze-head');

?>

<meta
    name="blaze-head-token"
    content="{{ $token }}"
/>
