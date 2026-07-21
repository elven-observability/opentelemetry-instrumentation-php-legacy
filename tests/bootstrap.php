<?php

require_once __DIR__ . '/../vendor/autoload.php';

// PHP 8.5 treats CLI output as sent headers for http_response_code(). Keep the
// PHPUnit printer buffered so HTTP status behavior can be tested consistently.
if (ob_get_level() === 0) {
    ob_start();
}
