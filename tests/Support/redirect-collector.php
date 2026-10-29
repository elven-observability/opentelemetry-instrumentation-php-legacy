<?php

$store = getenv('ELVEN_TEST_REDIRECT_STORE');
if ($store) {
    file_put_contents($store, $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
}
if ($_SERVER['REQUEST_URI'] === '/redirect') {
    header('Location: http://localhost:' . $_SERVER['SERVER_PORT'] . '/sink', true, 302);
} else {
    header('Content-Type: application/json');
    echo '{}';
}
