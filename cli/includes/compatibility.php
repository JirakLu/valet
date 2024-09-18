<?php

// Allow bypassing these checks if using Valet in a non-CLI app
if (php_sapi_name() !== 'cli') {
    return;
}

/**
 * Check the system's compatibility with Valet.
 */
$inTestingEnvironment = str_contains($_SERVER['SCRIPT_NAME'], 'phpunit');

if (PHP_OS !== 'Linux' && ! $inTestingEnvironment) {
    echo 'Valet only supports the Linux operating system.'.PHP_EOL;

    exit(1);
}

if (version_compare(PHP_VERSION, '8.0', '<')) {
    echo 'Valet requires PHP 8.0 or later.';

    exit(1);
}

if (exec('lsof -i :53') || exec('lsof -i :80') || exec('lsof -i :443')) {
    echo 'Valet requires ports 53, 80, and 443 to be open.';

    exit(1);
}
