<?php

function showInvalidAndQuit($msg = 'Authorization Required') {
    header('WWW-Authenticate: Basic realm="Tokenly Grade Signal"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Authorization Required';
    exit;
}

if (!isset($_SERVER['PHP_AUTH_USER'])) {
    showInvalidAndQuit();
} else {
    $user = $_SERVER['PHP_AUTH_USER'];
    $password = $_SERVER['PHP_AUTH_PW'];

    if ($user !== env('HTTP_AUTH_USERNAME') or $password !== env('HTTP_AUTH_PASSWORD') or !strlen($password)) {
        showInvalidAndQuit("Incorrect username or password");
    }
}
