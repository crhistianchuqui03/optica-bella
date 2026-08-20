<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';

iniciarSesionSegura();

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'], true)) {
    responderJSON(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
}

session_destroy();

responderJSON(['status' => 'success', 'message' => 'Sesión cerrada correctamente.'], 200);
