<?php

declare(strict_types=1);

/**
 * Helpers de sesión segura, respuestas JSON y middleware de autenticación.
 */

/**
 * Configura los parámetros de cookie seguros antes de iniciar la sesión.
 * El flag Secure solo se habilita sobre HTTPS.
 */
function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_name('PHPSESSID');
    session_start();
}

/**
 * Envía la respuesta JSON y termina la ejecución.
 */
function responderJSON(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Middleware que valida la sesión activa y los roles permitidos.
 * Devuelve la sesión actual si el usuario está autenticado.
 */
function requerirAutenticacion(array $rolesPermitidos = []): array
{
    iniciarSesionSegura();

    if (empty($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
        responderJSON(['status' => 'error', 'message' => 'No autenticado.'], 401);
    }

    if (!empty($rolesPermitidos) && !in_array($_SESSION['rol'], $rolesPermitidos, true)) {
        responderJSON(['status' => 'error', 'message' => 'No autorizado.'], 403);
    }

    return $_SESSION;
}
