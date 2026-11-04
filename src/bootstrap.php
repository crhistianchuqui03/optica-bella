<?php

declare(strict_types=1);

use App\Support\Env;

/**
 * Punto de arranque común para el Front Controller y scripts CLI.
 * Registra el autoloader, carga .env, fija la zona horaria y captura errores.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

spl_autoload_register(static function (string $clase): void {
    $prefijo = 'App\\';

    if (strncmp($clase, $prefijo, strlen($prefijo)) !== 0) {
        return;
    }

    $relativa = substr($clase, strlen($prefijo));
    $archivo  = BASE_PATH . '/src/' . str_replace('\\', '/', $relativa) . '.php';

    if (is_file($archivo)) {
        require_once $archivo;
    }
});

require_once BASE_PATH . '/config/database.php';

Env::cargar(BASE_PATH . '/.env');

date_default_timezone_set(Env::obtener('APP_TIMEZONE', 'America/Lima') ?? 'America/Lima');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(static function (Throwable $excepcion): void {
    error_log(sprintf(
        '[bellavista] %s: %s en %s:%d',
        $excepcion::class,
        $excepcion->getMessage(),
        $excepcion->getFile(),
        $excepcion->getLine()
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(
            ['status' => 'error', 'message' => 'Error interno del servidor.'],
            JSON_UNESCAPED_UNICODE
        );
    }
});