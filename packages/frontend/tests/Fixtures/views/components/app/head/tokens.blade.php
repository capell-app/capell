<?php

use Illuminate\Support\Str;

$token = Str::upper('blaze-head');

?>

<meta
    name="blaze-head-token"
    content="{{ $token }}"
/>
